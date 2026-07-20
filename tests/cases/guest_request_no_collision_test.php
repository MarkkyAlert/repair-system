<?php

declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Repositories\GuestTicketRequestRepository;
use App\Services\GuestTicketService;

// bug-hunt MED#6: guest submit caught EVERY 23000 (integrity constraint violation) as "คำขอนี้ถูกส่งไปแล้ว".
// But request_no AND submission_token are BOTH UNIQUE columns. request_no is a 24-bit random string with no
// retry, so under a burst of scans a birthday collision on request_no would surface as 23000 and a genuine NEW
// report got silently rejected as a duplicate. The fix distinguishes the two: submission_token dup = real
// double-submit (tell the user); request_no dup = unlucky random collision (regenerate and retry).

/** A 23000 PDOException shaped like MySQL's 1062 for a given UNIQUE key (getCode() is final, so forge via reflection). */
function grnc_dup(string $keyName): PDOException
{
    $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'GR-x' for key '{$keyName}'";
    $e = new PDOException($message);
    $code = new ReflectionProperty(Exception::class, 'code');
    $code->setAccessible(true);
    $code->setValue($e, '23000');
    $e->errorInfo = ['23000', 1062, "Duplicate entry 'GR-x' for key '{$keyName}'"];

    return $e;
}

/** GuestTicketService wired with a fake asset lookup (a scan always resolves) + a scripted requests repo. */
function grnc_service(GuestTicketRequestRepository $requests): GuestTicketService
{
    $c = tvm_container();
    $pdo = $c->get(PDO::class);
    $assets = new class ($pdo) extends AssetRepository {
        public function findActiveAssetByToken(string $token): ?array
        {
            return ['id' => 1, 'location_id' => 1]; // any scanned QR resolves to a real asset
        }
    };

    return new GuestTicketService(
        $requests,
        $assets,
        $c->get(App\Services\LoginRateLimiter::class),
        $c->get(App\Services\NotificationService::class),
        $pdo,
    );
}

function grnc_input(): array
{
    return [
        'guest_name' => 'ผู้แจ้ง ทดสอบ',
        'guest_phone' => '0810000000',
        'title' => 'ไฟดับ',
        'description' => 'ห้องประชุมไฟดับ',
        'form_token' => bin2hex(random_bytes(32)),
    ];
}

test('guest submit (MED#6): a request_no collision is retried with a fresh number, not rejected as a duplicate', function (): void {
    $ip = '203.0.113.' . random_int(10, 250);
    $rateKey = 'guest_submit:' . sha1($ip);

    // first create() collides on request_no (unlucky random), the retry succeeds
    $requests = new class (tvm_container()->get(PDO::class)) extends GuestTicketRequestRepository {
        public int $calls = 0;
        public function create(array $payload): int
        {
            $this->calls++;
            if ($this->calls === 1) {
                throw grnc_dup('uq_gtr_request_no');
            }

            return 4242; // the retry lands on a free number
        }
    };

    try {
        $result = grnc_service($requests)->submitGuestRequest('QR-TOKEN', grnc_input(), $ip);
        assert_same(4242, (int) ($result['id'] ?? 0), 'the report is created on retry — a random collision must not lose a genuine submission');
        assert_same(2, $requests->calls, 'exactly one retry happened after the request_no collision');
    } finally {
        tvm_container()->get(App\Services\LoginRateLimiter::class)->clear($rateKey);
        tvm_container()->get(PDO::class)->prepare("DELETE FROM notifications WHERE related_type='guest_request' AND related_id=4242")->execute();
    }
});

test('guest submit (MED#6): a submission_token collision IS still reported as an already-submitted duplicate', function (): void {
    $ip = '203.0.113.' . random_int(10, 250);
    $rateKey = 'guest_submit:' . sha1($ip);

    // every create() collides on submission_token → a real double-submit / replay, never retried
    $requests = new class (tvm_container()->get(PDO::class)) extends GuestTicketRequestRepository {
        public int $calls = 0;
        public function create(array $payload): int
        {
            $this->calls++;
            throw grnc_dup('uq_gtr_submission_token');
        }
    };

    try {
        $threw = false;
        $message = '';
        try {
            grnc_service($requests)->submitGuestRequest('QR-TOKEN', grnc_input(), $ip);
        } catch (DomainException $e) {
            $threw = true;
            $message = $e->getMessage();
        }
        assert_true($threw, 'a submission_token collision must surface as a duplicate-submit DomainException');
        assert_contains_str('ถูกส่งไปแล้ว', $message, 'the user is told the request was already submitted');
        assert_same(1, $requests->calls, 'a real duplicate is NOT retried');
    } finally {
        tvm_container()->get(App\Services\LoginRateLimiter::class)->clear($rateKey);
    }
});
