<?php
declare(strict_types=1);

use App\Services\GuestTicketService;
use App\Services\LoginRateLimiter;

// Tests for the public guest status lookup (/track) — GuestTicketService::lookupGuestStatus. Verifies the
// second factor (phone/email must match, else null), status mapping (new / converted→live ticket status /
// rejected→note), anti-enumeration (null on unknown request_no or wrong contact), and IP rate-limiting.
// Seeds its own guest_ticket_requests (+ a ticket) and clears the rate-limit keys it touches.

function gs_service(): GuestTicketService
{
    return tvm_container()->get(GuestTicketService::class);
}

function gs_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function gs_seed(string $no, string $phone, string $email, string $status, ?int $ticketId, ?string $note): void
{
    gs_pdo()->prepare(
        'INSERT INTO guest_ticket_requests (request_no, submission_token, guest_name, guest_phone, guest_email, title, description, status, converted_ticket_id, review_note, created_at)
         VALUES (?, ?, "เทส", ?, ?, "หัวข้อ", "รายละเอียด", ?, ?, ?, NOW())'
    )->execute([$no, 'tok-' . bin2hex(random_bytes(6)), $phone, $email, $status, $ticketId, $note]);
}

test('guest status: second factor + status mapping + anti-enumeration', function (): void {
    $rid = strtoupper(bin2hex(random_bytes(3)));
    $ip = '198.51.100.' . random_int(1, 254);
    $nos = ["GRT{$rid}N", "GRT{$rid}C", "GRT{$rid}R", "GRT{$rid}P"];
    $ticketId = 0;

    try {
        $loc = (int) gs_pdo()->query('SELECT COALESCE((SELECT id FROM locations LIMIT 1), 1)')->fetchColumn();
        $cat = (int) gs_pdo()->query('SELECT COALESCE((SELECT id FROM ticket_categories LIMIT 1), 1)')->fetchColumn();
        $pri = (int) gs_pdo()->query('SELECT COALESCE((SELECT id FROM priorities LIMIT 1), 1)')->fetchColumn();
        gs_pdo()->prepare(
            'INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id, status, requested_at)
             VALUES (?, "x", "x", 1, ?, ?, ?, "in_progress", NOW())'
        )->execute(["MT-GST-$rid", $loc, $cat, $pri]);
        $ticketId = (int) gs_pdo()->lastInsertId();

        gs_seed($nos[0], '0810000001', '', 'new', null, null);
        gs_seed($nos[1], '', "c$rid@t.com", 'converted', $ticketId, null);
        gs_seed($nos[2], '0810000003', '', 'rejected', null, 'ข้อมูลไม่เพียงพอ');
        gs_seed($nos[3], '081-999-8888', '', 'new', null, null);

        $new = gs_service()->lookupGuestStatus($nos[0], '0810000001', $ip);
        assert_true($new !== null && $new['status'] === 'new', 'new found with correct phone');
        assert_same('รอการตรวจสอบ', $new['status_label'], 'new → Thai label');

        assert_true(gs_service()->lookupGuestStatus($nos[0], '0899999999', $ip) === null, 'wrong phone → null (2nd factor)');

        // case-insensitive email + lowercase request_no both accepted
        $conv = gs_service()->lookupGuestStatus(strtolower($nos[1]), strtoupper("c$rid@t.com"), $ip);
        assert_true($conv !== null && $conv['status'] === 'converted', 'converted found (case-insensitive)');
        assert_same("MT-GST-$rid", $conv['ticket_no'], 'shows linked ticket_no');
        assert_same('กำลังดำเนินการ', $conv['ticket_status_label'], 'shows live ticket status label');

        $rej = gs_service()->lookupGuestStatus($nos[2], '0810000003', $ip);
        assert_true($rej !== null && $rej['status'] === 'rejected', 'rejected found');
        assert_same('ข้อมูลไม่เพียงพอ', $rej['review_note'], 'rejected shows the review note');

        assert_true(gs_service()->lookupGuestStatus("GR-NOPE-$rid", 'x', $ip) === null, 'unknown request_no → null');

        // phone stored with separators still matches a plain-digits lookup (and vice-versa)
        assert_true(gs_service()->lookupGuestStatus($nos[3], '0819998888', $ip) !== null, 'phone matches ignoring format (dashes)');
    } finally {
        gs_pdo()->prepare('DELETE FROM guest_ticket_requests WHERE request_no IN (?, ?, ?, ?)')->execute($nos);
        if ($ticketId > 0) {
            gs_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        tvm_container()->get(LoginRateLimiter::class)->clear('guest_lookup:' . sha1($ip));
    }
});

test('guest status: lookup rate-limits by IP after the cap', function (): void {
    $ip = '198.51.100.' . random_int(1, 254);
    $rl = tvm_container()->get(LoginRateLimiter::class);

    try {
        $threw = false;
        for ($i = 0; $i < 12; $i++) {
            try {
                gs_service()->lookupGuestStatus("GR-RL-$i", 'x', $ip);
            } catch (DomainException) {
                $threw = true;
                break;
            }
        }
        assert_true($threw, 'lookup throws once the per-IP cap is exceeded');
    } finally {
        $rl->clear('guest_lookup:' . sha1($ip));
    }
});
