<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use App\Repositories\GuestTicketRequestRepository;
use DomainException;

class GuestTicketService
{
    private const RATE_LIMIT_MAX = 3;
    private const RATE_LIMIT_DECAY = 600; // 10 minutes

    public function __construct(
        private GuestTicketRequestRepository $requests,
        private AssetRepository $assets,
        private LoginRateLimiter $rateLimiter,
    ) {
    }

    public function submitGuestRequest(string $token, array $input, string $ipAddress): array
    {
        // Honeypot — silent reject
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return ['request_no' => 'HP-' . substr(md5($ipAddress . microtime()), 0, 8)];
        }

        $rateKey = 'guest_submit:' . sha1($ipAddress !== '' ? $ipAddress : 'unknown');
        if ($this->rateLimiter->tooManyAttempts($rateKey, self::RATE_LIMIT_MAX, self::RATE_LIMIT_DECAY)) {
            $seconds = $this->rateLimiter->availableIn($rateKey, self::RATE_LIMIT_DECAY);
            throw new DomainException('คุณส่งคำขอเกินกำหนด กรุณาลองใหม่ในอีก ' . max(1, (int) ceil($seconds / 60)) . ' นาที');
        }

        $name = trim((string) ($input['guest_name'] ?? ''));
        $email = strtolower(trim((string) ($input['guest_email'] ?? '')));
        $phone = trim((string) ($input['guest_phone'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($name === '' || $title === '' || $description === '') {
            throw new DomainException('กรุณากรอกชื่อ หัวข้อ และรายละเอียดให้ครบ');
        }
        if ($email === '' && $phone === '') {
            throw new DomainException('กรุณากรอกอีเมลหรือเบอร์โทรอย่างน้อย 1 อย่าง');
        }
        if (mb_strlen($name) > 150) {
            throw new DomainException('ชื่อยาวเกินกำหนด');
        }
        if (mb_strlen($title) > 200) {
            throw new DomainException('หัวข้อยาวเกินกำหนด');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('รูปแบบอีเมลไม่ถูกต้อง');
        }
        if ($phone !== '' && !preg_match('/^[0-9+\-() .]{4,30}$/', $phone)) {
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        $asset = $this->assets->findActiveAssetByToken($token);
        if ($asset === null) {
            throw new DomainException('ไม่พบ QR ของทรัพย์สินที่สแกน');
        }

        $this->rateLimiter->hit($rateKey, self::RATE_LIMIT_DECAY);

        $requestNo = $this->generateRequestNo();
        $id = $this->requests->create([
            'request_no' => $requestNo,
            'asset_id' => (int) ($asset['id'] ?? 0) > 0 ? (int) $asset['id'] : null,
            'location_id' => (int) ($asset['location_id'] ?? 0) > 0 ? (int) $asset['location_id'] : null,
            'guest_name' => $name,
            'guest_email' => $email,
            'guest_phone' => $phone,
            'title' => $title,
            'description' => $description,
            'submitted_ip' => $ipAddress,
        ]);

        return [
            'id' => $id,
            'request_no' => $requestNo,
        ];
    }

    public function getModerationData(string $status, int $page, int $perPage = 20): array
    {
        $perPage = max(5, min($perPage, 100));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $totals = $this->requests->countByStatus();
        $matched = $this->requests->countMatching($status);
        $totalPages = $matched > 0 ? (int) ceil($matched / $perPage) : 1;

        return [
            'requests' => $this->requests->listByStatus($status, $perPage, $offset),
            'totals' => $totals,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $matched,
                'totalPages' => $totalPages,
            ],
        ];
    }

    public function convertToTicket(int $requestId, array $viewer, int $priorityId, int $categoryId, TicketService $tickets): int
    {
        $request = $this->requests->findById($requestId);
        if ($request === null) {
            throw new DomainException('ไม่พบ guest request');
        }
        if ((string) $request['status'] !== 'new') {
            throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
        }

        $contact = trim(((string) $request['guest_email']) . ' ' . ((string) $request['guest_phone']));
        $titlePrefix = '[จาก Guest: ' . (string) $request['guest_name'] . '] ';
        $descriptionPrefix = "ผู้แจ้ง (Guest): " . (string) $request['guest_name'] . "\n"
            . "ติดต่อ: " . ($contact !== '' ? $contact : '-') . "\n"
            . "Request No: " . (string) $request['request_no'] . "\n\n";

        $ticketInput = [
            'title' => $titlePrefix . (string) $request['title'],
            'description' => $descriptionPrefix . (string) $request['description'],
            'priority_id' => $priorityId,
            'ticket_category_id' => $categoryId,
            'location_id' => (int) ($request['location_id'] ?? 0),
            'asset_id' => (int) ($request['asset_id'] ?? 0) > 0 ? (int) $request['asset_id'] : '',
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
            'submission_token' => bin2hex(random_bytes(32)),
        ];

        $ticketId = $tickets->createTicket($viewer, $ticketInput, []);
        $this->requests->markConverted($requestId, $ticketId, (int) ($viewer['id'] ?? 0));

        return $ticketId;
    }

    public function rejectRequest(int $requestId, array $viewer, string $note): void
    {
        $request = $this->requests->findById($requestId);
        if ($request === null) {
            throw new DomainException('ไม่พบ guest request');
        }
        if ((string) $request['status'] !== 'new') {
            throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
        }

        $this->requests->markRejected($requestId, (int) ($viewer['id'] ?? 0), $note);
    }

    private function generateRequestNo(): string
    {
        return 'GR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
