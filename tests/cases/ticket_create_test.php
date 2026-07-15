<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// Unit coverage for TicketService::createTicket — the most fundamental state-changing flow, previously only
// exercised by the E2E happy path. Covers the validation branches, the mandatory submission_token, the
// submission_token idempotency (double-submit → one ticket), and the happy path (ticket + SLA tracks created).
// Drives the real service against the test DB; created tickets (+ their notifications) are deleted in finally
// (children — work_order / sla_tracks / activity_log — cascade on ticket delete). No Request bind needed:
// TicketService has no AuditLogger, and the ticket.created notification is best-effort.

function tc_service(): TicketService
{
    return tvm_container()->get(TicketService::class);
}

function tc_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** The same reference set createTicket validates against (active priorities/categories/locations/assets). */
function tc_ref(): array
{
    return tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
}

function tc_requester(): array
{
    return ['id' => 1, 'role' => 'requester']; // seed requester
}

/** A valid 64-hex submission token (createTicket rejects anything else as "form expired"). */
function tc_token(): string
{
    return bin2hex(random_bytes(32));
}

/** A fully-valid createTicket input; override one key to exercise a single failing branch. */
function tc_valid_input(array $ref, array $overrides = []): array
{
    return array_merge([
        'submission_token' => tc_token(),
        'title' => 'TC ticket ' . bin2hex(random_bytes(3)),
        'description' => 'TC description',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], $overrides);
}

test('ticketCreate: a malformed numeric reference ("1junk") is rejected, not coerced to its prefix (round F1)', function (): void {
    // (int)"1junk" === 1, which would silently pass as priority/category/location #1. strict_int rejects it.
    $ref = tc_ref();
    foreach (['priority_id', 'ticket_category_id', 'location_id', 'asset_id'] as $field) {
        $threw = false;
        try {
            tc_service()->createTicket(tc_requester(), tc_valid_input($ref, [$field => '1junk']));
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, "$field = '1junk' must be rejected as a non-integer, not coerced to 1");
    }
});

// R4-F1: channel is a TRUSTED ARGUMENT, never read from user input — a web user crafting channel=phone in the
// POST must NOT be able to spoof the ticket's origin. The web path (default arg) always records 'web'.
test('ticketCreate(security): a crafted channel in the web input cannot override the origin (R4-F1)', function (): void {
    $ref = tc_ref();
    $ticketId = 0;
    try {
        // a malicious web POST tries to masquerade as a phone/walk-in intake
        $ticketId = tc_service()->createTicket(tc_requester(), tc_valid_input($ref, ['channel' => 'phone']), []);
        assert_true($ticketId > 0, 'the ticket was created');
        assert_same('web', (string) tc_pdo()->query("SELECT channel FROM tickets WHERE id = $ticketId")->fetchColumn(), 'the injected channel is ignored — a web ticket stays channel=web');
    } finally {
        tc_cleanup($ticketId);
    }
});

/** Assert createTicket($valid + overrides) throws exactly $message. Rejects throw before any insert. */
function tc_reject(array $ref, array $overrides, string $message, string $ctx): void
{
    $threw = false;
    try {
        tc_service()->createTicket(tc_requester(), tc_valid_input($ref, $overrides), []);
    } catch (DomainException $e) {
        $threw = true;
        assert_same($message, $e->getMessage(), $ctx);
    }
    assert_true($threw, "$ctx — must throw");
}

function tc_cleanup(int $ticketId): void
{
    if ($ticketId <= 0) {
        return;
    }
    tc_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
    tc_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades work_order / sla_tracks / activity_log
}

// ── validation branches ──

test('ticketCreate: required / length / severity / reference / token branches each reject', function (): void {
    $ref = tc_ref();

    // mandatory submission_token (checked first — anything not 64-hex → form expired)
    tc_reject($ref, ['submission_token' => ''], 'แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', 'missing submission token');
    tc_reject($ref, ['submission_token' => 'not-a-token'], 'แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', 'bad submission token');

    // required fields
    tc_reject($ref, ['title' => ''], 'กรุณากรอกหัวข้อและรายละเอียดของปัญหาให้ครบถ้วน', 'empty title');
    tc_reject($ref, ['description' => ''], 'กรุณากรอกหัวข้อและรายละเอียดของปัญหาให้ครบถ้วน', 'empty description');

    // title length
    tc_reject($ref, ['title' => str_repeat('a', 201)], 'หัวข้อปัญหาต้องไม่เกิน 200 ตัวอักษร', 'title over 200 chars');

    // severity enum
    tc_reject($ref, ['impact_level' => 'extreme'], 'ค่าผลกระทบหรือความเร่งด่วนไม่ถูกต้อง', 'invalid impact level');
    tc_reject($ref, ['urgency_level' => 'bogus'], 'ค่าผลกระทบหรือความเร่งด่วนไม่ถูกต้อง', 'invalid urgency level');

    // reference existence
    tc_reject($ref, ['priority_id' => 999999], 'กรุณาเลือก Priority, Category และ Location ให้ถูกต้อง', 'non-existent priority');
    tc_reject($ref, ['ticket_category_id' => 999999], 'กรุณาเลือก Priority, Category และ Location ให้ถูกต้อง', 'non-existent category');
    tc_reject($ref, ['location_id' => 999999], 'กรุณาเลือก Priority, Category และ Location ให้ถูกต้อง', 'non-existent location');
});

test('ticketCreate: asset must exist and belong to the selected location', function (): void {
    $ref = tc_ref();
    $asset = $ref['assets'][0] ?? null;
    assert_true($asset !== null, 'precondition: at least one asset in reference data');
    $assetLocation = (int) $asset['location_id'];
    // a valid location that is NOT the asset's location
    $otherLocation = null;
    foreach ($ref['locations'] as $loc) {
        if ((int) $loc['id'] !== $assetLocation) {
            $otherLocation = (int) $loc['id'];
            break;
        }
    }
    assert_true($otherLocation !== null, 'precondition: a second location exists for the mismatch case');

    tc_reject($ref, ['asset_id' => 999999, 'location_id' => $assetLocation], 'Asset ที่เลือกไม่ถูกต้อง', 'non-existent asset');
    tc_reject($ref, ['asset_id' => (int) $asset['id'], 'location_id' => $otherLocation], 'Asset ที่เลือกไม่ได้อยู่ใน Location ที่ระบุ', 'asset not in the selected location');
});

// ── ⭐ idempotency ──

test('ticketCreate(idempotency): the same submission_token returns the same ticket — no duplicate', function (): void {
    $ref = tc_ref();
    $token = tc_token();
    $input = tc_valid_input($ref, ['submission_token' => $token]);
    $ticketId = 0;
    try {
        $ticketId = tc_service()->createTicket(tc_requester(), $input, []);
        assert_true($ticketId > 0, 'first submit creates a ticket');

        $again = tc_service()->createTicket(tc_requester(), $input, []); // same token (double-click / refresh)
        assert_same($ticketId, $again, 'the second submit returns the SAME ticket id (deduped, not created again)');

        $count = tc_pdo()->prepare('SELECT COUNT(*) FROM tickets WHERE submission_token = ?');
        $count->execute([$token]);
        assert_same(1, (int) $count->fetchColumn(), 'exactly one ticket row exists for the submission_token');
    } finally {
        tc_cleanup($ticketId);
    }
});

// ── ⭐ atomicity (G1): a failing child insert rolls back the whole ticket — no orphan / partial write ──
// Uses the shared FailingPdo fault injector (tests/failing_pdo.php): createTicket inserts tickets →
// ticket_approvals → ticket_sla_tracks → ticket_activity_logs in one transaction; forcing the SLA-track insert
// to throw must roll the ticket + approval back (a ticket with no SLA tracks would silently corrupt SLA reports).

test('ticketCreate(atomicity): a failing SLA-track insert rolls back the whole ticket — no orphan / partial write (G1)', function (): void {
    $ref = tc_ref();
    $token = tc_token();
    $input = tc_valid_input($ref, ['submission_token' => $token]);

    $threw = false;
    // A fresh TicketService (transient) picks up the swapped failing PDO. The ticket + approval rows insert, then
    // the ticket_sla_tracks insert throws mid-transaction — the whole thing must roll back (nothing partial).
    with_failing_pdo('ticket_sla_tracks', function () use ($input, &$threw): void {
        try {
            tvm_container()->get(TicketService::class)->createTicket(tc_requester(), $input, []);
        } catch (Throwable) {
            $threw = true;
        }
    });

    try {
        assert_true($threw, 'the injected SLA-track insert failure must surface an error');

        // no orphan: the ticket row never survived (so neither did its cascade children).
        $ticketCount = tc_pdo()->prepare('SELECT COUNT(*) FROM tickets WHERE submission_token = ?');
        $ticketCount->execute([$token]);
        assert_same(0, (int) $ticketCount->fetchColumn(), 'the ticket was rolled back — no orphan/partial ticket for the token');
    } finally {
        // defensive: nothing should persist, but if the rollback were defeated (power-proof) clean the leaked row
        $stmt = tc_pdo()->prepare('SELECT id FROM tickets WHERE submission_token = ?');
        $stmt->execute([$token]);
        $leaked = $stmt->fetchColumn();
        if ($leaked !== false) {
            tc_cleanup((int) $leaked);
        }
    }
});

// ── happy path ──

test('ticketCreate: happy path stores the ticket (pending_approval) + creates SLA tracks with future targets', function (): void {
    $ref = tc_ref();
    $input = tc_valid_input($ref);
    $ticketId = 0;
    try {
        $ticketId = tc_service()->createTicket(tc_requester(), $input, []);
        assert_true($ticketId > 0, 'ticket created');

        $row = tc_pdo()->query('SELECT title, requester_id, status, priority_id, location_id FROM tickets WHERE id = ' . (int) $ticketId)->fetch(PDO::FETCH_ASSOC);
        assert_true($row !== false, 'ticket row exists');
        assert_same($input['title'], $row['title'], 'title stored');
        assert_same(1, (int) $row['requester_id'], 'requester_id = the viewer');
        assert_same('pending_approval', $row['status'], 'initial status is pending_approval');

        // SLA tracks: created (response + resolution) with sane, non-past targets (structural — no exact timestamp)
        $trackCount = tc_pdo()->prepare('SELECT COUNT(*) FROM ticket_sla_tracks WHERE ticket_id = ?');
        $trackCount->execute([$ticketId]);
        assert_true((int) $trackCount->fetchColumn() >= 1, 'at least one SLA track was created');

        $future = tc_pdo()->prepare('SELECT COUNT(*) FROM ticket_sla_tracks WHERE ticket_id = ? AND target_at > NOW()');
        $future->execute([$ticketId]);
        assert_true((int) $future->fetchColumn() >= 1, 'an SLA track has a target_at in the future (seed priorities all have > 0 SLA minutes)');
    } finally {
        tc_cleanup($ticketId);
    }
});
