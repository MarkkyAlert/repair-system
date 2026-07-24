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
    private const RATE_LIMIT_DECAY = 600; // 10 นาที
    private const LOOKUP_RATE_LIMIT_MAX = 10;   // เช็คสถานะ: 10 ครั้ง/10 นาที ต่อ IP (กัน brute-force second factor)
    private const LOOKUP_RATE_LIMIT_DECAY = 600;
    private const REQUEST_NO_MAX_ATTEMPTS = 5;  // สุ่ม request_no ใหม่ได้กี่ครั้งถ้าบังเอิญชนเลขซ้ำ ก่อนยอมแพ้

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
        // Honeypot (กับดักดักบอท) — ปฏิเสธแบบเงียบ ๆ
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

        // request_no กับ submission_token ต่างก็เป็นคอลัมน์ UNIQUE ทั้งคู่ ถ้าจับ 23000 รวมแล้วเหมาว่าเป็น
        // "คำขอซ้ำ" เสมอจะผิด: เลข request_no สุ่มมี 24 บิต บังเอิญชนกันได้จริงตอนคนสแกนพร้อมกันเยอะ ๆ แล้วคำขอ
        // ใหม่จริง ๆ จะถูกทิ้งเงียบ ๆ ว่า "ส่งไปแล้ว". จึงแยก: ชน submission_token = double-submit จริง (แจ้งผู้ใช้),
        // ชน request_no = เลขสุ่มบังเอิญซ้ำ (สุ่มใหม่แล้วลองอีกครั้ง).
        $id = null;
        for ($attempt = 1; $attempt <= self::REQUEST_NO_MAX_ATTEMPTS; $attempt++) {
            $requestNo = $this->generateRequestNo();
            try {
                $id = $this->requests->create([
                    'request_no' => $requestNo,
                    // submission_token (คอลัมน์ UNIQUE) — idempotency ระดับ DB กัน double-submit/replay
                    // ที่หลุด session check มาได้ เป็นชั้นกันซ้อนคู่กับ one-time form token
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
                break;
            } catch (\PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $detail = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
                // ชน submission_token → คำขอนี้ถูกส่งไปแล้วจริง (กดซ้ำ/replay)
                if (str_contains($detail, 'submission_token')) {
                    throw new DomainException('คำขอนี้ถูกส่งไปแล้ว');
                }
                // ชน request_no (หรือ 23000 อื่นที่ระบุไม่ได้) แต่ยังลองได้อีก → สุ่มเลขใหม่วนต่อ
                if (str_contains($detail, 'request_no') && $attempt < self::REQUEST_NO_MAX_ATTEMPTS) {
                    continue;
                }
                throw $exception;
            }
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
        $totals = $this->requests->countByStatus();
        $matched = $this->requests->countMatching($status);
        ['page' => $page, 'offset' => $offset, 'totalPages' => $totalPages] = paginate($page, $perPage, $matched);

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
        // input ที่จำเป็นต่อการแปลง — เช็คตรงนี้ ไม่ใช่ที่ controller กฎจะได้ใช้กับทุกคนที่เรียก
        // convertToTicket และเช็คก่อนแตะ lock/DB. strict_int ปฏิเสธค่าผิดรูปอย่าง
        // "1junk" ไม่เหมือน (int) cast เดิมของ controller ที่แอบเก็บแค่ "1" เงียบ ๆ.
        $priorityId = strict_int($priorityId, 'ความสำคัญ');
        $categoryId = strict_int($categoryId, 'หมวดหมู่');
        if ($priorityId <= 0 || $categoryId <= 0) {
            throw new DomainException('กรุณาเลือกความสำคัญและหมวดหมู่');
        }

        // จับ convert/reject ของ request เดียวกันทำทีละคนด้วย advisory lock — เช็ค status='new' + สร้าง
        // ticket + link ทั้งหมดใต้ lock เดียว จะได้ไม่มีทางที่ convert 2 คนพร้อมกัน (หรือ convert
        // แข่งกับ reject) สร้าง ticket ที่ไม่ถูกผูกกับ request แล้วกลายเป็น orphan.
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
            $originalTitle = (string) $request['title'];
            // ข้อความ "[จาก Guest: name] {title}" ที่ประกอบขึ้นอาจยาวเกินลิมิต 200 ตัวของ ticket-title (name ≤150 +
            // title ≤200) ทำให้ guest request ที่ส่งสำเร็จแล้วแปลงไม่ผ่าน. เลยตัด
            // title ไว้ที่ 200 แล้วเก็บต้นฉบับเต็มไว้ใน description ข้อมูลจะได้ไม่หาย.
            $composedTitle = '[จาก Guest: ' . (string) $request['guest_name'] . '] ' . $originalTitle;
            if (mb_strlen($composedTitle) > 200) {
                $composedTitle = mb_substr($composedTitle, 0, 200);
            }
            $descriptionPrefix = "ผู้แจ้ง (Guest): " . (string) $request['guest_name'] . "\n"
                . "ติดต่อ: " . ($contact !== '' ? $contact : '-') . "\n"
                . "Request No: " . (string) $request['request_no'] . "\n"
                . "หัวข้อเดิม: " . $originalTitle . "\n\n";

            $ticketInput = [
                'title' => $composedTitle,
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
            // ไม่งั้นรายงานมิติแผนกผู้แจ้งจะนับงานคนนอกเข้าแผนกของ admin/manager
            // (ตัดสินใจโดยเจ้าของระบบ). requester_id ยังเป็นผู้กดแปลงโดยตั้งใจ (ต้องมีคนภายในถือสิทธิ์ปิดงาน).
            $converterWithoutDepartment = $viewer;
            unset($converterWithoutDepartment['department_id']);

            // ตรวจว่าทรัพย์สินที่แขกสแกน "ดริฟต์" ไปจาก snapshot ตอนแจ้งไหม (ถูกย้าย/ปลดระวาง หรือสถานที่ถูกปิด) —
            // เก็บผลไว้ flag ให้ผู้อนุมัติหลังสร้าง ticket เสร็จ (การที่มีคนสแกนเจอ = ข้อมูลทรัพย์สินอาจต้องตรวจสอบ)
            [$assetNeedsReview, $assetCodeForReview] = $this->detectConvertAssetDrift(
                (int) ($request['asset_id'] ?? 0),
                (int) ($request['location_id'] ?? 0)
            );

            // Atomic: create ticket + claim/link ในทรานแซกชันเดียว. createTicket ตรวจ inTransaction() แล้ว
            // participate (ไม่ commit/notify เอง) → ถ้า claimAndLink ล้มหรือคืน false ให้ rollback ทั้งคู่
            // จึง "ได้ทั้งคู่ หรือไม่ได้เลย" ไม่มี ticket กำพร้า (สร้างแล้วแต่ request ยังไม่ถูก mark converted).
            $this->db->beginTransaction();
            try {
                // channel='qr' + trustAssetLocation=true: asset/location มาจาก snapshot ตอนแขกสแกน QR เชื่อถือได้
                // แปลงได้แม้ทรัพย์สินถูกย้าย/ปลดระวางหลังแจ้ง (คงลิงก์ทรัพย์สินไว้ + flag ให้ admin ตรวจด้านล่าง)
                $ticketId = $tickets->createTicket($converterWithoutDepartment, $ticketInput, [], 'qr', true);
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

            // flag ให้ผู้อนุมัติไปตรวจสอบสถานะทรัพย์สิน ถ้าทรัพย์สินที่แขกสแกนไม่ตรงปัจจุบันแล้ว — best-effort
            if ($assetNeedsReview) {
                try {
                    $this->notifications->notifyGuestConvertAssetReview($ticketId, $assetCodeForReview);
                } catch (Throwable $exception) {
                    log_caught_exception('guest.convert.asset_review', $exception, ['ticket_id' => $ticketId]);
                }
            }

            return $ticketId;
        } finally {
            $this->requests->releaseConvertLock($requestId);
        }
    }

    public function rejectRequest(int $requestId, array $viewer, string $note): void
    {
        // ใช้ lock ตัวเดียวกับ convert → reject กับ convert เลยทำทีละคน (กัน reject แทรกกลางระหว่าง
        // ตอน convert เช็ค status กับตอน claimAndLink ซึ่งเคยเป็นต้นตอ orphan ticket)
        $this->requests->acquireConvertLock($requestId);
        try {
            $request = $this->requests->findById($requestId);
            if ($request === null) {
                throw new DomainException('ไม่พบ guest request');
            }
            if ((string) $request['status'] !== 'new') {
                throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
            }

            // update แบบมีเงื่อนไข atomic (WHERE status='new'); ถ้าได้ false แปลว่ามี convert/reject พร้อมกัน
            // เคลมไปก่อนแล้ว ต้องแจ้งเรื่องนั้นแทนที่จะบอกว่าสำเร็จซึ่งชวนเข้าใจผิด.
            if (!$this->requests->markRejected($requestId, (int) ($viewer['id'] ?? 0), $note)) {
                throw new DomainException('Request นี้ถูกดำเนินการแล้ว');
            }
        } finally {
            $this->requests->releaseConvertLock($requestId);
        }
    }

    /**
     * เช็คสถานะคำขอ guest แบบ public — ต้องมี second factor (เบอร์/อีเมลที่แจ้งไว้) เพราะ request_no เดาได้.
     * คืน null เมื่อไม่พบ/second factor ไม่ตรง (ผู้เรียกควรโชว์ error กลาง ๆ กัน enumeration). throw เมื่อ rate-limit เกิน.
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

    /**
     * ทรัพย์สินที่แขกสแกน (asset_id ตอนแจ้ง) ตอนนี้ยังตรงกับ snapshot ไหม — ดริฟต์ = ถูกย้ายไป location อื่น, ปลดระวาง
     * (status ไม่ใช่ active/maintenance ตามที่ getCreateFormReferenceData ถือว่าใช้งานได้), หรือสถานที่เดิมถูกปิด.
     * @return array{0: bool, 1: string} [ต้องให้ admin ตรวจสอบไหม, asset_code]
     */
    private function detectConvertAssetDrift(int $assetId, int $snapshotLocationId): array
    {
        if ($assetId <= 0) {
            return [false, ''];
        }

        $stmt = $this->db->prepare(
            'SELECT a.asset_code, a.status, a.location_id AS current_location_id,
                    (SELECT is_active FROM locations WHERE id = :loc) AS snapshot_location_active
             FROM assets a WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $assetId, 'loc' => $snapshotLocationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return [true, '']; // ทรัพย์สินถูกลบไปแล้ว
        }

        $drift = !in_array((string) $row['status'], ['active', 'maintenance'], true)
            || (int) $row['current_location_id'] !== $snapshotLocationId
            || (int) ($row['snapshot_location_active'] ?? 0) !== 1;

        return [$drift, (string) ($row['asset_code'] ?? '')];
    }

    // protected เป็น seam ให้เทสต์ override บังคับเลขชนกันได้ (พิสูจน์ว่า collision → สุ่มใหม่ ไม่ใช่ทิ้งเป็นคำขอซ้ำ)
    protected function generateRequestNo(): string
    {
        return 'GR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}
