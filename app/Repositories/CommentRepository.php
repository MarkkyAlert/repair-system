<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use Throwable;

class CommentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * นับ comment ของ ticket ตาม visibility ของผู้ดู (requester ไม่เห็น internal) —
     * ใช้เป็น signal สำหรับ live poll ตรวจว่ามี comment ใหม่.
     */
    public function countForTicket(int $ticketId, bool $includeInternal): int
    {
        $sql = 'SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = :ticket_id';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * ดึง comment ทั้ง thread ของ ticket (join ชื่อ/role ผู้เขียน) เรียงตามเวลาสร้าง.
     * ความปลอดภัย: $includeInternal=false จะกรอง comment ภายใน (is_internal=1) ออก — ผู้เรียกต้องส่งค่านี้ตาม role ของผู้ดู
     * @return array<int, array<string, mixed>>
     */
    public function getCommentsByTicketId(int $ticketId, bool $includeInternal): array
    {
        $sql =
            'SELECT c.id, c.ticket_id, c.user_id, c.parent_id, c.body, c.is_internal, c.created_at, c.updated_at, c.version, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = :ticket_id';

        if (!$includeInternal) {
            $sql .= ' AND c.is_internal = 0';
        }

        $sql .= ' ORDER BY c.created_at ASC, c.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * แบบสำหรับ live-poll: เฉพาะ comment ที่ใหม่กว่า $afterId (id > afterId) สำหรับ feed หน้ารายละเอียด ticket
     * จำกัดการอ่านไว้เฉพาะส่วนที่ client ยังไม่มี แทนที่จะโหลดทั้ง thread แล้วค่อยกรองใน
     * PHP — id ที่เป็น auto-increment ทำให้ id > afterId เท่ากับ "ถูกโพสต์หลังอันสุดท้ายที่ client มี"
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCommentsAfterId(int $ticketId, int $afterId, bool $includeInternal): array
    {
        $sql =
            'SELECT c.id, c.ticket_id, c.user_id, c.parent_id, c.body, c.is_internal, c.created_at, c.updated_at, c.version, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.ticket_id = :ticket_id
               AND c.id > :after_id';

        if (!$includeInternal) {
            $sql .= ' AND c.is_internal = 0';
        }

        $sql .= ' ORDER BY c.created_at ASC, c.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId, 'after_id' => $afterId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ดึง comment เดี่ยวตาม id (join ชื่อ/role ผู้เขียน + version สำหรับ optimistic lock).
     * ความปลอดภัย: ไม่กรอง is_internal — คืนทั้ง comment ภายในด้วย ผู้เรียกต้องเช็ค visibility ของผู้ดูเอง
     * @return array<string, mixed>|null null เมื่อไม่พบ comment
     */
    public function findCommentById(int $commentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.ticket_id, c.user_id, c.parent_id, c.body, c.is_internal, c.created_at, c.updated_at, c.version, u.full_name AS author_name, u.role AS author_role
             FROM ticket_comments c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.id = :comment_id
             LIMIT 1'
        );
        $stmt->execute(['comment_id' => $commentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * สร้าง comment ใหม่บน ticket.
     * ผลข้างเคียง: INSERT ticket_comments หนึ่งแถว (ไม่ครอบ transaction เอง). idempotent ด้วย submission_token —
     * ถ้า INSERT ชน (โพสต์ซ้ำ) จะ fallback ไปค้นแถวเดิมจาก token แล้วคืนแทนการสร้างซ้ำ
     * @param string $submissionToken token กัน double-submit; ต้อง unique ต่อการโพสต์หนึ่งครั้ง
     * @return array{id: int, created: bool} created=false เมื่อชน token เดิม (คืน comment ที่มีอยู่)
     * @throws Throwable เมื่อ INSERT ล้มเหลวด้วยเหตุอื่นที่ไม่ใช่การชน token เดิม
     */
    public function createComment(int $ticketId, int $userId, string $body, bool $isInternal, string $submissionToken, ?int $parentId = null): array
    {
        $createdAt = date('Y-m-d H:i:s');
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ticket_comments (ticket_id, user_id, parent_id, submission_token, body, is_internal, created_at, updated_at)
                 VALUES (:ticket_id, :user_id, :parent_id, :submission_token, :body, :is_internal, :created_at, :updated_at)'
            );
            $stmt->execute([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'parent_id' => $parentId,
                'submission_token' => $submissionToken,
                'body' => $body,
                'is_internal' => $isInternal ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return ['id' => (int) $this->db->lastInsertId(), 'created' => true];
        } catch (Throwable $exception) {
            $stmt = $this->db->prepare(
                'SELECT id FROM ticket_comments WHERE submission_token = :submission_token LIMIT 1'
            );
            $stmt->execute(['submission_token' => $submissionToken]);
            $commentId = $stmt->fetchColumn();
            if ($commentId !== false) {
                return ['id' => (int) $commentId, 'created' => false];
            }

            throw $exception;
        }
    }

    /**
     * แก้ไข comment ด้วย optimistic lock (version) กันสองคนเขียนทับกันในวินาทีเดียว.
     * ผลข้างเคียง: UPDATE ticket_comments (body/is_internal/version+1) เฉพาะเมื่อ version ในฟอร์มยังตรงกับใน DB
     * @param int $originalVersion ค่า version ที่ฟอร์มถืออยู่ตอนเปิดแก้ (มาจากตอนโหลด comment)
     * @throws DomainException เมื่อไม่พบ comment หรือ comment ถูกแก้โดยผู้อื่นไปแล้ว (version ไม่ตรง)
     */
    public function updateComment(int $commentId, string $body, bool $isInternal, int $originalVersion): void
    {
        // optimistic lock ใช้ version ที่เป็น integer ไม่ใช่ updated_at: updated_at เป็น DATETIME ละเอียดแค่วินาที ถ้าเอามาเป็น token
        // การแก้สองครั้งในวินาทีเดียวกัน (โดยแถวเพิ่งถูกแตะครั้งสุดท้ายในวินาทีนั้น) อาจ match ทั้งคู่ แล้วครั้งหลังเขียนทับครั้งแรกแบบเงียบ ๆ
        // version เพิ่มขึ้นทุกครั้งที่เขียน ฟอร์มแก้ไขที่ถือ version เก่าไว้จึงไม่มีวัน match
        $stmt = $this->db->prepare(
            'UPDATE ticket_comments
             SET body = :body,
                 is_internal = :is_internal,
                 version = version + 1,
                 updated_at = :updated_at
             WHERE id = :comment_id
               AND version = :original_version'
        );
        $stmt->execute([
            'body' => $body,
            'is_internal' => $isInternal ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'comment_id' => $commentId,
            'original_version' => $originalVersion,
        ]);

        if ($stmt->rowCount() > 0) {
            return;
        }

        $existsStmt = $this->db->prepare('SELECT version FROM ticket_comments WHERE id = :comment_id LIMIT 1');
        $existsStmt->execute(['comment_id' => $commentId]);
        $currentVersion = $existsStmt->fetchColumn();

        if ($currentVersion === false) {
            throw new DomainException('ไม่พบ comment ที่ต้องการแก้ไข');
        }

        if ((int) $currentVersion !== $originalVersion) {
            throw new DomainException('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }
    }

    /**
     * ลบ comment ถาวร.
     * ผลข้างเคียง: DELETE ticket_comments หนึ่งแถว (hard delete, ไม่ครอบ transaction เอง); ตัวเมธอดไม่ลบไฟล์แนบบนดิสก์เอง
     */
    public function deleteComment(int $commentId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM ticket_comments
             WHERE id = :comment_id'
        );
        $stmt->execute(['comment_id' => $commentId]);
    }
}
