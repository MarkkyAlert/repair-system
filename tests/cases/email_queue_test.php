<?php
declare(strict_types=1);

use App\Repositories\EmailQueueRepository;
use App\Repositories\UserRepository;
use App\Services\EmailQueueService;
use App\Services\EmailTemplateService;
use App\Services\MailerService;

// Coverage for the email-queue background worker — previously untested. Verifies the atomic claim
// (queued → processing + attempts++), the double-claim guard that prevents double-send (a recently-claimed
// processing row is not re-claimed), re-claim of a stuck processing row past the timeout, and the send
// failure path (retry below max_attempts, failed at max_attempts). Rows are seeded with a very old
// available_at so LIMIT-based claims deterministically pick them first; each seeded row is deleted in finally.

function eq_repo(): EmailQueueRepository
{
    return tvm_container()->get(EmailQueueRepository::class);
}

function eq_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** A worker with a mailer that always fails — to drive the retry / fail branches of processDueEmails. */
function eq_failing_worker(): EmailQueueService
{
    $failingMailer = new class () extends MailerService {
        public function send(array $message): void
        {
            throw new RuntimeException('EQ test: simulated send failure');
        }
    };
    return new EmailQueueService(
        tvm_container()->get(EmailQueueRepository::class),
        tvm_container()->get(UserRepository::class),
        tvm_container()->get(EmailTemplateService::class),
        $failingMailer
    );
}

/** Seed one email_queue row (very old available_at → sorts first for LIMIT claims). Returns its id. */
function eq_seed(array $overrides = []): int
{
    $now = date('Y-m-d H:i:s');
    $cols = array_merge([
        'to_email' => 'eq_' . bin2hex(random_bytes(4)) . '@x.test',
        'subject' => 'EQTEST ' . bin2hex(random_bytes(4)),
        'status' => 'queued',
        'attempts' => 0,
        'max_attempts' => 3,
        'available_at' => '2000-01-01 00:00:00',
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    $fields = implode(', ', array_keys($cols));
    $placeholders = implode(', ', array_map(static fn (string $k): string => ":$k", array_keys($cols)));
    eq_pdo()->prepare("INSERT INTO email_queue ($fields) VALUES ($placeholders)")->execute($cols);

    return (int) eq_pdo()->lastInsertId();
}

function eq_row(int $id): array
{
    $stmt = eq_pdo()->prepare('SELECT status, attempts, error_message, available_at FROM email_queue WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function eq_delete(int $id): void
{
    eq_pdo()->prepare('DELETE FROM email_queue WHERE id = ?')->execute([$id]);
}

/** int ids of the rows a claim returned. */
function eq_claimed_ids(array $claimed): array
{
    return array_map('intval', array_column($claimed, 'id'));
}

// ── claim ──

test('emailQueue: a due queued email is claimed → processing + attempts incremented', function (): void {
    $id = eq_seed(['status' => 'queued']);
    try {
        $claimed = eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 900));
        assert_true(in_array($id, eq_claimed_ids($claimed), true), 'the due queued email was claimed');

        $row = eq_row($id);
        assert_same('processing', $row['status'], 'status moves to processing');
        assert_same(1, (int) $row['attempts'], 'attempts incremented to 1');
    } finally {
        eq_delete($id);
    }
});

// ── ⭐ double-claim guard (no double-send) ──

test('emailQueue(idempotency): a recently-claimed processing row is NOT re-claimed (atomic claim → no double-send)', function (): void {
    $id = eq_seed(['status' => 'processing', 'attempts' => 1, 'updated_at' => date('Y-m-d H:i:s')]); // claimed just now
    try {
        // processingExpiredBefore = 1 hour ago → an in-flight row (updated now) is NOT stuck → must not be re-claimed
        $claimed = eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600));
        assert_false(in_array($id, eq_claimed_ids($claimed), true), 'the in-flight processing row is not re-claimed');
        assert_same(1, (int) eq_row($id)['attempts'], 'its attempts stays 1 (not double-claimed → the email is not sent twice)');
    } finally {
        eq_delete($id);
    }
});

test('emailQueue: a stuck processing row past the timeout is re-claimed', function (): void {
    $id = eq_seed(['status' => 'processing', 'attempts' => 1, 'updated_at' => date('Y-m-d H:i:s', time() - 7200)]); // stuck 2h
    try {
        $claimed = eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600)); // stuck-before = 1h ago
        assert_true(in_array($id, eq_claimed_ids($claimed), true), 'the stuck processing row is re-claimed');
        assert_same(2, (int) eq_row($id)['attempts'], 'attempts incremented again on re-claim');
    } finally {
        eq_delete($id);
    }
});

// ── send failure: retry vs fail ──

test('emailQueue(retry): a send failure below max_attempts requeues the email for retry', function (): void {
    $id = eq_seed(['status' => 'queued', 'attempts' => 0, 'max_attempts' => 3]);
    try {
        $result = eq_failing_worker()->processDueEmails(1); // limit 1 → only the oldest (this seeded row)

        $row = eq_row($id);
        assert_same('queued', $row['status'], 'requeued (retry), not failed');
        assert_same(1, (int) $row['attempts'], 'attempts incremented to 1');
        assert_true((string) ($row['error_message'] ?? '') !== '', 'the failure reason is recorded');
        assert_same(1, (int) ($result['retried'] ?? 0), 'reported as retried');
    } finally {
        eq_delete($id);
    }
});

test('emailQueue(fail): a send failure at max_attempts marks the email failed', function (): void {
    $id = eq_seed(['status' => 'queued', 'attempts' => 0, 'max_attempts' => 1]); // claim → attempts 1 >= max 1 → failed
    try {
        $result = eq_failing_worker()->processDueEmails(1);

        assert_same('failed', eq_row($id)['status'], 'marked failed once attempts reach max_attempts');
        assert_same(1, (int) ($result['failed'] ?? 0), 'reported as failed');
    } finally {
        eq_delete($id);
    }
});

// ── ⭐ concurrency (F2): a stale worker cannot clobber a row another worker reclaimed ──
// Worker A claims (attempts→1). The row goes stale and worker B reclaims it (attempts→2, still processing).
// A then finishes late and calls its terminal updates with its OLD claim attempt (1) — each must compare-and-set
// on (status='processing' AND attempts=claimAttempt) and no-op, leaving B's claim intact. Remove the attempts
// predicate and A's markSent clobbers B → red (power-proof).
test('emailQueue(concurrency): a stale worker cannot clobber a row another worker reclaimed (F2)', function (): void {
    $id = eq_seed(['status' => 'queued', 'attempts' => 0]);
    try {
        eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 900)); // A claims
        assert_same(1, (int) eq_row($id)['attempts'], 'A claimed → attempts 1');

        // the row goes stale; B reclaims it
        eq_pdo()->prepare('UPDATE email_queue SET updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 7200), $id]);
        eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600)); // B reclaims
        assert_same(2, (int) eq_row($id)['attempts'], 'B reclaimed → attempts 2, still processing');

        // A is stale (claimAttempt = 1); every terminal update must no-op — the row belongs to B now
        assert_false(eq_repo()->markSent($id, 1), 'stale A markSent no-ops (row reclaimed by B)');
        assert_false(eq_repo()->releaseForRetry($id, 'late error', 300, 1), 'stale A releaseForRetry no-ops');
        assert_false(eq_repo()->markFailed($id, 'late error', 1), 'stale A markFailed no-ops');

        $row = eq_row($id);
        assert_same('processing', $row['status'], "B's claim is intact — status still processing (A did not flip it)");
        assert_same(2, (int) $row['attempts'], "B's attempts (2) untouched by stale A");

        // sanity: the CURRENT claimant (B, claimAttempt = 2) can complete its own claim
        assert_true(eq_repo()->markSent($id, 2), 'the current claimant can mark its own row sent');
        assert_same('sent', eq_row($id)['status'], 'B completed its own claim');
    } finally {
        eq_delete($id);
    }
});

// ── M-3: จ๊อบที่ทำ worker ตาย ต้องมีสถานะจบ ไม่ใช่วนหยิบซ้ำตลอดกาล ──
// เพดาน max_attempts เดิมบังคับอยู่ใน catch ของ service เท่านั้น ความล้มเหลวที่ "ฆ่าโปรเซส" (OOM จาก body ใหญ่ /
// fatal ในตัวส่งเมล / SMTP ค้างจนหมดเวลา) จึงไม่มีวันไปถึง catch นั้น: แถวค้างเป็น processing → หมดเวลา → ถูกหยิบ
// ซ้ำ → ตายอีก วนไม่จบและ attempts บวกไม่หยุด. attempts เป็น TINYINT UNSIGNED (สูงสุด 255) พอชนเพดาน UPDATE
// ของทั้ง batch จะ error ใน strict mode = แถวเสียแถวเดียวทำให้ทั้งคิวส่งเมลไม่ออกอีกเลย
test('emailQueue M-3: a crashed job that already burned its attempts is not re-claimed forever', function (): void {
    $stale = date('Y-m-d H:i:s', time() - 7200); // ค้างมา 2 ชม.
    $poison = eq_seed(['status' => 'processing', 'attempts' => 3, 'max_attempts' => 3, 'updated_at' => $stale]);

    try {
        $claimed = eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600));
        assert_true(!in_array($poison, eq_claimed_ids($claimed), true), 'a job at its attempt cap is not picked up again');

        $row = eq_row($poison);
        assert_same('failed', (string) $row['status'], 'it reaches a terminal status instead of looping');
        assert_contains_str('หยุดส่งอัตโนมัติ', (string) $row['error_message'], 'and says why, so the admin can see it in the failed tab');
        assert_same(3, (int) $row['attempts'], 'giving up does not inflate the attempt count further');
    } finally {
        eq_delete($poison);
    }
});

test('emailQueue M-3: a crashed job still under its cap IS retried, and the admin can rescue a given-up one', function (): void {
    $stale = date('Y-m-d H:i:s', time() - 7200);
    $retryable = eq_seed(['status' => 'processing', 'attempts' => 1, 'max_attempts' => 3, 'updated_at' => $stale]);
    $poison = eq_seed(['status' => 'processing', 'attempts' => 3, 'max_attempts' => 3, 'updated_at' => $stale]);

    try {
        $claimed = eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600));
        assert_true(in_array($retryable, eq_claimed_ids($claimed), true), 'a stale job below the cap is still retried — the fix is not over-broad');
        assert_same(2, (int) eq_row($retryable)['attempts'], 'and its attempt count advances normally');

        // the given-up one is now in the failed tab, where the existing admin retry button reaches it
        assert_same('failed', (string) eq_row($poison)['status'], 'the capped job is failed, not queued');
        assert_true(eq_repo()->requeueForRetry($poison), 'an admin can still manually retry it');
        assert_same('queued', (string) eq_row($poison)['status'], 'a manual retry puts it back in the queue');
        assert_same(0, (int) eq_row($poison)['attempts'], 'with a clean slate');
    } finally {
        eq_delete($retryable);
        eq_delete($poison);
    }
});

test('emailQueue M-3: one poisoned row does not block the healthy mail behind it', function (): void {
    $stale = date('Y-m-d H:i:s', time() - 7200);
    $poison = eq_seed(['status' => 'processing', 'attempts' => 3, 'max_attempts' => 3, 'updated_at' => $stale]);
    $healthyA = eq_seed();
    $healthyB = eq_seed();

    try {
        $ids = eq_claimed_ids(eq_repo()->claimDueEmails(100, date('Y-m-d H:i:s', time() - 3600)));
        assert_true(in_array($healthyA, $ids, true) && in_array($healthyB, $ids, true), 'normal queued mail still goes out');
        assert_true(!in_array($poison, $ids, true), 'the poisoned row is simply skipped');
    } finally {
        eq_delete($poison);
        eq_delete($healthyA);
        eq_delete($healthyB);
    }
});
