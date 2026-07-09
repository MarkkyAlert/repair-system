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
    $failingMailer = new class extends MailerService {
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
