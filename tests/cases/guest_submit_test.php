<?php
declare(strict_types=1);

use App\Services\GuestTicketService;
use App\Services\LoginRateLimiter;

// Unit coverage for GuestTicketService::submitGuestRequest (public QR guest report) — previously only the
// E2E happy path. Covers the honeypot silent-reject, the required-field / email-or-phone validation, the
// per-IP rate limit, the unknown-QR-token rejection, and the happy path (a guest_ticket_requests row is
// created). Check order in the code: honeypot → rate limit → validation → token resolution → create; so the
// validation branches don't need a real token. Created rows (+ notifications) and the rate key are cleaned
// in finally.

function gsub_service(): GuestTicketService
{
    return tvm_container()->get(GuestTicketService::class);
}

function gsub_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function gsub_rate_limiter(): LoginRateLimiter
{
    return tvm_container()->get(LoginRateLimiter::class);
}

/** An active QR token that resolves to an asset (for token/happy cases). */
function gsub_active_token(): string
{
    return (string) gsub_pdo()->query('SELECT token FROM asset_qr_tokens WHERE is_active = 1 LIMIT 1')->fetchColumn();
}

/** A fully-valid guest submit input (honeypot empty); override to break one branch. */
function gsub_valid_input(string $name, array $overrides = []): array
{
    return array_merge([
        'guest_name' => $name,
        'guest_email' => 'gsub_' . bin2hex(random_bytes(3)) . '@x.test',
        'guest_phone' => '',
        'title' => 'GS title',
        'description' => 'GS description',
        'website' => '', // honeypot must be empty for a real submit
        'form_token' => bin2hex(random_bytes(32)),
    ], $overrides);
}

function gsub_count_by_name(string $name): int
{
    $stmt = gsub_pdo()->prepare('SELECT COUNT(*) FROM guest_ticket_requests WHERE guest_name = ?');
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn();
}

function gsub_cleanup(string $name, string $ip): void
{
    $pdo = gsub_pdo();
    // notifications from notifyGuestRequestSubmitted (related_type = guest_request)
    $stmt = $pdo->prepare('SELECT id FROM guest_ticket_requests WHERE guest_name = ?');
    $stmt->execute([$name]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
        $pdo->prepare("DELETE FROM notifications WHERE related_type = 'guest_request' AND related_id = ?")->execute([(int) $requestId]);
    }
    $pdo->prepare('DELETE FROM guest_ticket_requests WHERE guest_name = ?')->execute([$name]);
    gsub_rate_limiter()->clear('guest_submit:' . sha1($ip !== '' ? $ip : 'unknown'));
}

// ── honeypot ──

test('guestSubmit(honeypot): a filled honeypot field is silently rejected — no request row is created', function (): void {
    $name = 'GS hp ' . bin2hex(random_bytes(3));
    $ip = '198.51.100.' . random_int(1, 254);
    try {
        // valid everything, but the honeypot "website" is filled (a bot) → silent reject
        $result = gsub_service()->submitGuestRequest(gsub_active_token(), gsub_valid_input($name, ['website' => 'http://spam']), $ip);
        assert_true(str_starts_with((string) ($result['request_no'] ?? ''), 'HP-'), 'a fake HP receipt is returned');
        assert_same(0, gsub_count_by_name($name), 'NO guest request row was created for the honeypot submission');
    } finally {
        gsub_cleanup($name, $ip);
    }
});

// ── validation ──

test('guestSubmit(validation): missing name/title/description and missing email+phone are rejected', function (): void {
    $ip = '198.51.100.' . random_int(1, 254);
    try {
        $reject = static function (array $overrides, string $message, string $ctx) use ($ip): void {
            $threw = false;
            try {
                gsub_service()->submitGuestRequest('any-token', gsub_valid_input('GS v', $overrides), $ip);
            } catch (DomainException $e) {
                $threw = true;
                assert_same($message, $e->getMessage(), $ctx);
            }
            assert_true($threw, "$ctx — must throw");
        };

        $reject(['guest_name' => ''], 'กรุณากรอกชื่อ หัวข้อ และรายละเอียดให้ครบ', 'empty name');
        $reject(['title' => ''], 'กรุณากรอกชื่อ หัวข้อ และรายละเอียดให้ครบ', 'empty title');
        $reject(['description' => ''], 'กรุณากรอกชื่อ หัวข้อ และรายละเอียดให้ครบ', 'empty description');
        $reject(['guest_email' => '', 'guest_phone' => ''], 'กรุณากรอกอีเมลหรือเบอร์โทรอย่างน้อย 1 อย่าง', 'no email and no phone');
        $reject(['guest_email' => 'not-an-email'], 'รูปแบบอีเมลไม่ถูกต้อง', 'invalid email format');
    } finally {
        gsub_rate_limiter()->clear('guest_submit:' . sha1($ip));
    }
});

// ── ⭐ rate limit ──

test('guestSubmit(rate-limit): submitting past the per-IP cap is blocked', function (): void {
    $ip = '198.51.100.' . random_int(1, 254);
    $key = 'guest_submit:' . sha1($ip); // must match GuestTicketService::submitGuestRequest
    try {
        for ($i = 0; $i < 3; $i++) { // RATE_LIMIT_MAX = 3
            gsub_rate_limiter()->hit($key, 600);
        }

        $threw = false;
        try {
            // rate limit is checked before validation, so even minimal input is blocked
            gsub_service()->submitGuestRequest('any-token', ['guest_name' => 'x'], $ip);
        } catch (DomainException $e) {
            $threw = true;
            assert_contains_str('เกินกำหนด', $e->getMessage());
        }
        assert_true($threw, 'exceeding the guest-submit cap must be blocked');
    } finally {
        gsub_rate_limiter()->clear($key);
    }
});

// ── token resolution + happy ──

test('guestSubmit: an unknown QR token is rejected; a valid one creates a guest request row', function (): void {
    $ipBad = '198.51.100.' . random_int(1, 254);
    $badName = 'GS bad ' . bin2hex(random_bytes(3));
    try {
        // unknown token → rejected (valid fields, so only the token stops it)
        $threw = false;
        try {
            gsub_service()->submitGuestRequest('definitely-not-a-real-token', gsub_valid_input($badName), $ipBad);
        } catch (DomainException $e) {
            $threw = true;
            assert_same('ไม่พบ QR ของทรัพย์สินที่สแกน', $e->getMessage());
        }
        assert_true($threw, 'an unknown QR token is rejected');
        assert_same(0, gsub_count_by_name($badName), 'no row created for an unknown token');
    } finally {
        gsub_cleanup($badName, $ipBad);
    }

    $ipOk = '198.51.100.' . random_int(1, 254);
    $okName = 'GS ok ' . bin2hex(random_bytes(3));
    try {
        $result = gsub_service()->submitGuestRequest(gsub_active_token(), gsub_valid_input($okName), $ipOk);
        assert_true((int) ($result['id'] ?? 0) > 0, 'happy submit returns a request id');
        assert_true(str_starts_with((string) ($result['request_no'] ?? ''), 'GTR') || (string) ($result['request_no'] ?? '') !== '', 'a request_no is returned');

        $row = gsub_pdo()->prepare('SELECT status, asset_id, title FROM guest_ticket_requests WHERE id = ?');
        $row->execute([(int) $result['id']]);
        $stored = $row->fetch(PDO::FETCH_ASSOC);
        assert_true($stored !== false, 'the guest request row exists');
        assert_same('new', $stored['status'], 'a new request is created with status new');
        assert_same('GS title', $stored['title'], 'title stored');
        assert_true((int) ($stored['asset_id'] ?? 0) > 0, 'the request is linked to the scanned asset');
    } finally {
        gsub_cleanup($okName, $ipOk);
    }
});
