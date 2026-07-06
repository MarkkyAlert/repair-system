<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;
use RuntimeException;
use Throwable;

class GuestTicketRequestRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * id สูงสุดของ guest request — signal สำหรับ live poll หน้าคิว (คำขอใหม่ = id เพิ่ม).
     */
    public function getMaxRequestId(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(id), 0) FROM guest_ticket_requests')->fetchColumn();
    }

    public function create(array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO guest_ticket_requests (request_no, submission_token, asset_id, location_id, guest_name, guest_email, guest_phone, title, description, submitted_ip, status, created_at)
             VALUES (:request_no, :submission_token, :asset_id, :location_id, :guest_name, :guest_email, :guest_phone, :title, :description, :submitted_ip, "new", NOW())'
        );
        $stmt->execute([
            'request_no' => $payload['request_no'],
            'submission_token' => ($payload['submission_token'] ?? '') !== '' ? $payload['submission_token'] : null,
            'asset_id' => $payload['asset_id'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'guest_name' => $payload['guest_name'],
            'guest_email' => $payload['guest_email'] !== '' ? $payload['guest_email'] : null,
            'guest_phone' => $payload['guest_phone'] !== '' ? $payload['guest_phone'] : null,
            'title' => $payload['title'],
            'description' => $payload['description'],
            'submitted_ip' => $payload['submitted_ip'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT g.*, a.asset_code, a.name AS asset_name, l.name AS location_name
             FROM guest_ticket_requests g
             LEFT JOIN assets a ON a.id = g.asset_id
             LEFT JOIN locations l ON l.id = g.location_id
             WHERE g.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** ค้นด้วยเลขอ้างอิง + join ticket ที่แปลงแล้ว (ticket_no/status) — ใช้หน้า public track สถานะ. */
    public function findByRequestNo(string $requestNo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT g.*, t.ticket_no, t.status AS ticket_status
             FROM guest_ticket_requests g
             LEFT JOIN tickets t ON t.id = g.converted_ticket_id
             WHERE g.request_no = :request_no
             LIMIT 1'
        );
        $stmt->execute(['request_no' => $requestNo]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listByStatus(string $status, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $where = '';
        $params = [];
        if (in_array($status, ['new', 'converted', 'rejected'], true)) {
            $where = 'WHERE g.status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(
            "SELECT g.id, g.request_no, g.guest_name, g.guest_email, g.guest_phone, g.title, g.description, g.status,
                    g.created_at, g.converted_ticket_id, a.asset_code, a.name AS asset_name, l.name AS location_name
             FROM guest_ticket_requests g
             LEFT JOIN assets a ON a.id = g.asset_id
             LEFT JOIN locations l ON l.id = g.location_id
             $where
             ORDER BY g.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countByStatus(): array
    {
        $stmt = $this->db->query('SELECT status, COUNT(*) AS total FROM guest_ticket_requests GROUP BY status');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totals = ['new' => 0, 'converted' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (isset($totals[$key])) {
                $totals[$key] = (int) $row['total'];
            }
        }
        return $totals;
    }

    public function countMatching(string $status): int
    {
        $where = '';
        $params = [];
        if (in_array($status, ['new', 'converted', 'rejected'], true)) {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM guest_ticket_requests $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Atomically mark a 'new' request converted AND link its ticket in ONE statement — status และ
     * converted_ticket_id ถูก set พร้อมกัน จึงไม่มีทางเกิด request 'converted' ที่ converted_ticket_id
     * เป็น NULL (invariant นี้บังคับด้วยโครงสร้าง SQL). Ticket ต้องถูกสร้าง (commit) มาก่อนเรียก.
     * Returns false เมื่อ concurrent convert/reject ชิงไปแล้ว (status ไม่ใช่ 'new') — caller ควร
     * surface + ตรวจสอบ ticket ที่สร้างไว้ (ticket ยังเป็น valid record).
     */
    public function claimAndLink(int $id, int $ticketId, int $reviewerId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET status = "converted", converted_ticket_id = :ticket_id,
                 reviewed_by = :reviewer, reviewed_at = NOW()
             WHERE id = :id AND status = "new"'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markRejected(int $id, int $reviewerId, string $note): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET status = "rejected", reviewed_by = :reviewer, reviewed_at = NOW(), review_note = :note
             WHERE id = :id AND status = "new"'
        );
        $stmt->execute([
            'reviewer' => $reviewerId,
            'note' => $note,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Connection-scoped advisory lock ต่อ 1 guest request — serialize convert/reject ที่แข่งกัน
     * (mirror TicketRepository::acquireNamedLock). ถือ lock ระหว่างตรวจ status + สร้าง ticket + link
     * เพื่อกันการสร้าง orphan ticket จาก concurrent convert.
     */
    public function acquireConvertLock(int $requestId): void
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 5)');
        $stmt->execute(['name' => 'guest-req-convert-' . $requestId]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new RuntimeException('ระบบกำลังประมวลผลคำขอนี้ กรุณาลองใหม่อีกครั้ง');
        }
    }

    public function releaseConvertLock(int $requestId): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => 'guest-req-convert-' . $requestId]);
        } catch (Throwable) {
            // Releasing a connection-scoped lock must not hide the original operation result.
        }
    }
}
