<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AssetRepository;
use App\Repositories\GuestTicketRequestRepository;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

class GuestTicketService
{
    private const RATE_LIMIT_MAX = 3;
    private const RATE_LIMIT_DECAY = 600; // 10 minutes
    private const LOOKUP_RATE_LIMIT_MAX = 10;   // เช็คสถานะ: 10 ครั้ง/10 นาที ต่อ IP (กัน brute-force second factor)
    private const LOOKUP_RATE_LIMIT_DECAY = 600;

    public function __construct(
        private GuestTicketRequestRepository $requests,
        private AssetRepository $assets,
        private LoginRateLimiter $rateLimiter,
        private NotificationService $notifications,
        private PDO $db,
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
        if ($email !== '' && !is_valid_email($email)) {
            throw new DomainException('รูปแบบอีเมลไม่ถูกต้อง');
        }
        if ($phone !== '' && !valid_phone_format($phone)) {
            throw new DomainException('รูปแบบเบอร์โทรไม่ถูกต้อง');
        }

        $asset = $this->assets->findActiveAssetByToken($token);
        if ($asset === null) {
            throw new DomainException('ไม่พบ QR ของทรัพย์สินที่สแกน');
        }

        $this->rateLimiter->hit($rateKey, self::RATE_LIMIT_DECAY);

        $requestNo = $this->generateRequestNo();
        try {
            $id = $this->requests->create([
                'request_no' => $requestNo,
                // submission_token (UNIQUE) — DB-level idempotency กัน double-submit/replay
                // ที่หลุด session check (defense-in-depth คู่กับ one-time form token)
                'submission_token' => (string) ($input['form_token'] ?? ''),
                'asset_id' => (int) ($asset['id'] ?? 0) > 0 ? (int) $asset['id'] : null,
                'location_id' => (int) ($asset['location_id'] ?? 0) > 0 ? (int) $asset['location_id'] : null,
                'guest_name' => $name,
                'guest_email' => $email,
                'guest_phone' => $phone,
                'title' => $title,
                'description' => $description,
                'submitted_ip' => $ipAddress,
            ]);
        } catch (\PDOException $exception) {
            // 23000 = integrity constraint violation (ซ้ำ submission_token) → double-submit
            if ($exception->getCode() === '23000') {
                throw new DomainException('คำขอนี้ถูกส่งไปแล้ว');
            }
            throw $exception;
        }

        // แจ้ง manager/admin ทันที (in-app bell) ว่ามีคำขอ guest ใหม่ — best-effort:
        // guest submit ต้องสำเร็จเสมอแม้ notify พลาด
        try {
            $this->notifications->notifyGuestRequestSubmitted($id, $requestNo, $name);
        } catch (Throwable $exception) {
            log_caught_exception('guest.submit.notify', $exception, ['request' => $requestNo]);
        }

        return [
            'id' => $id,
            'request_no' => $requestNo,
        ];
    }

    /** id สูงสุดของ guest request — baseline/signal สำหรับ live poll หน้าคิว. */
    public function getQueueMaxId(): int
    {
        return $this->requests->getMaxRequestId();
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

    public function convertToTicket(int $requestId, array $viewer, int|string $priorityId, int|string $categoryId, TicketService $tickets): int
    {
        // Required conversion inputs — validated here (not the controller) so the rule holds for every
        // caller of convertToTicket, and is checked before any lock/DB work is done. strict_int rejects a
        // malformed "1junk" instead of the controller's old (int) cast silently keeping the "1" prefix (F1).
        $priorityId = strict_int($priorityId, 'ความสำคัญ');
        $categoryId = strict_int($categoryId, 'หมวดหมู่');
        if ($priorityId <= 0 || $categoryId <= 0) {
            throw new DomainException('กรุณาเลือกความสำคัญและหมวดหมู่');
        }

        // Serialize convert/reject บน request เดียวกันด้วย advisory lock — ตรวจ status='new' + สร้าง
        // ticket + link ทั้งหมดภายใต้ lock เดียว จึงไม่มีทางที่ concurrent convert 2 คน (หรือ convert
        // แข่ง reject) จะสร้าง ticket ที่ไม่ถูกผูกกับ request (orphan).
        $this->requests->acquireConvertLock($requestId);
        try {
            $request = $this->requests->findById($requestId);
            if ($request === null) {
                throw new DomainException('ไม่พบ guest request');
            }
            if ((string) $request['status'] !== 'new') {
                // คนแพ้ของการแข่งกัน convert/reject หยุดที่นี่ — ยังไม่ได้สร้าง ticket จึงไม่มี orphan
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

            // ผู้แจ้งตัวจริงคือ guest (ไม่มีบัญชี/แผนก) — ห้ามให้ ticket สืบทอด "แผนก" ของคนที่กดแปลง
            // ไม่งั้นรายงานมิติแผนกผู้แจ้งจะนับงานคนนอกเข้าแผนกของ admin/manager (logic-review F3,
            // business-confirmed). requester_id ยังเป็นผู้กดแปลงโดยตั้งใจ (ต้องมีคนภายในถือสิทธิ์ปิดงาน).
            $converterWithoutDepartment = $viewer;
            unset($converterWithoutDepartment['department_id']);

            // Atomic: create ticket + claim/link ในทรานแซกชันเดียว. createTicket ตรวจ inTransaction() แล้ว
            // participate (ไม่ commit/notify เอง) → ถ้า claimAndLink ล้มหรือคืน false ให้ rollback ทั้งคู่
            // จึง "ได้ทั้งคู่ หรือไม่ได้เลย" ไม่มี ticket กำพร้า (สร้างแล้วแต่ request ยังไม่ถูก mark converted).
            $this->db->beginTransaction();
            try {
                $ticketId = $tickets->createTicket($converterWithoutDepartment, $ticketInput, []);
                if (!$this->requests->claimAndLink($requestId, $ticketId, (int) ($viewer['id'] ?? 0))) {
                    throw new RuntimeException('เชื่อมโยง Ticket กับ guest request ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
                }
                $this->db->commit();
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }

            // ticket + link durable แล้ว จึงแจ้งผู้อนุมัติ — วางหลัง commit (createNotification เปิด
            // transaction ของตัวเอง จึงต้องไม่อยู่ในทรานแซกชันด้านบน) และเป็น best-effort.
            $this->notifications->notifyTicketEvent($ticketId, 'ticket.created', (int) ($viewer['id'] ?? 0));

            return $ticketId;
        } finally {
            $this->requests->releaseConvertLock($requestId);
        }
    }

    public function rejectRequest(int $requestId, array $viewer, string $note): void
    {
        // ใช้ lock ตัวเดียวกับ convert → reject กับ convert serialize กัน (กัน reject แทรกระหว่าง
        // convert ตรวจ status กับ claimAndLink ซึ่งเป็นต้นตอ orphan ticket เดิม)
        $this->requests->acquireConvertLock($requestId);
        try {
            $request = $this->requests->findById($requestId);
            if ($request === null) {
                throw new DomainException('ไม่พบ guest request');
            }
            if ((string) $request['status'] !== 'new') {
                throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
            }

            // Atomic conditional update (WHERE status='new'); false means a concurrent convert/reject
            // already claimed it, so surface that instead of reporting a misleading success.
            if (!$this->requests->markRejected($requestId, (int) ($viewer['id'] ?? 0), $note)) {
                throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
            }
        } finally {
            $this->requests->releaseConvertLock($requestId);
        }
    }

    /**
     * เช็คสถานะคำขอ guest แบบ public — ต้องมี second factor (เบอร์/อีเมลที่แจ้งไว้) เพราะ request_no เดาได้.
     * คืน null เมื่อไม่พบ/second factor ไม่ตรง (เรียกใช้ควรแสดง error กลาง ๆ กัน enumeration). throw เมื่อ rate-limit เกิน.
     * @return array{request_no:string, guest_name:string, created_at:string, status:string, status_label:string,
     *   status_tone:string, ticket_no:?string, ticket_status_label:?string, ticket_status_tone:?string, review_note:?string}|null
     */
    public function lookupGuestStatus(string $requestNo, string $contact, string $ipAddress): ?array
    {
        $rateKey = 'guest_lookup:' . sha1($ipAddress !== '' ? $ipAddress : 'unknown');
        if ($this->rateLimiter->tooManyAttempts($rateKey, self::LOOKUP_RATE_LIMIT_MAX, self::LOOKUP_RATE_LIMIT_DECAY)) {
            $seconds = $this->rateLimiter->availableIn($rateKey, self::LOOKUP_RATE_LIMIT_DECAY);
            throw new DomainException('ตรวจสอบสถานะบ่อยเกินไป กรุณาลองใหม่ในอีก ' . max(1, (int) ceil($seconds / 60)) . ' นาที');
        }
        $this->rateLimiter->hit($rateKey, self::LOOKUP_RATE_LIMIT_DECAY);

        $requestNo = strtoupper(trim($requestNo));
        $contact = trim($contact);
        if ($requestNo === '' || $contact === '') {
            return null;
        }

        $row = $this->requests->findByRequestNo($requestNo);
        if ($row === null) {
            return null;
        }

        // second factor: เบอร์ตรง (เทียบเฉพาะตัวเลข — กันฟอร์แมตต่าง เช่น 081-234-5678 vs 0812345678)
        // หรือ อีเมลตรง (case-insensitive) — ไม่ตรง = ปฏิบัติเหมือนไม่พบ (กัน enumeration)
        $phoneDigits = preg_replace('/\D/', '', (string) ($row['guest_phone'] ?? ''));
        $contactDigits = preg_replace('/\D/', '', $contact);
        $email = strtolower(trim((string) ($row['guest_email'] ?? '')));
        $matches = ($phoneDigits !== '' && $contactDigits !== '' && $phoneDigits === $contactDigits)
            || ($email !== '' && $email === strtolower($contact));
        if (!$matches) {
            return null;
        }

        $status = (string) ($row['status'] ?? 'new');
        $ticketStatus = $status === 'converted' ? (string) ($row['ticket_status'] ?? '') : '';

        return [
            'request_no' => (string) ($row['request_no'] ?? $requestNo),
            'guest_name' => (string) ($row['guest_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'status' => $status,
            'status_label' => guest_request_status_label_th($status),
            'status_tone' => match ($status) {
                'converted' => 'success', 'rejected' => 'danger', default => 'warning'
            },
            'ticket_no' => $status === 'converted' ? (string) ($row['ticket_no'] ?? '') : null,
            'ticket_status_label' => $ticketStatus !== '' ? ticket_status_label_th($ticketStatus) : null,
            'ticket_status_tone' => $ticketStatus !== '' ? ticket_status_tone($ticketStatus) : null,
            'review_note' => $status === 'rejected' ? (string) ($row['review_note'] ?? '') : null,
        ];
    }

    private function generateRequestNo(): string
    {
        return 'GR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
