<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class AttachmentRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * บันทึกเมทาดาทาไฟล์แนบลง DB.
     * ผลข้างเคียง: INSERT ticket_attachments หนึ่งแถว (ไม่ครอบ transaction เอง); ตัวเมธอด "ไม่" เขียนไฟล์ลงดิสก์ —
     * ผู้เรียกต้อง move/เก็บไฟล์จริงเองแล้วส่ง disk_path/stored_name ที่ชี้ไฟล์นั้นมาใน $payload
     * @param array<string, mixed> $payload ต้องมี 'ticket_id','comment_id','uploaded_by','original_name',
     *        'stored_name','disk_path','mime_type','file_size' (created_at เติมให้อัตโนมัติ)
     * @return int id ของแถวที่เพิ่งสร้าง
     */
    public function create(array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ticket_attachments
                (ticket_id, comment_id, uploaded_by, original_name, stored_name, disk_path, mime_type, file_size, created_at)
             VALUES
                (:ticket_id, :comment_id, :uploaded_by, :original_name, :stored_name, :disk_path, :mime_type, :file_size, :created_at)'
        );
        $stmt->execute($payload + ['created_at' => date('Y-m-d H:i:s')]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * ดึงไฟล์แนบเดี่ยวตาม id (join is_internal ของ comment ที่ผูกไว้ เพื่อให้ผู้เรียกใช้ตัดสินสิทธิ์ดาวน์โหลด).
     * ความปลอดภัย: ไม่กรอง visibility — คืนแม้ไฟล์ที่อยู่ใต้ comment ภายใน ผู้เรียกต้องเช็ค is_internal เอง
     * @return array<string, mixed>|null null เมื่อไม่พบ
     */
    public function findById(int $attachmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT a.*, c.is_internal
             FROM ticket_attachments a
             LEFT JOIN ticket_comments c ON c.id = a.comment_id
             WHERE a.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $attachmentId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * ดึงไฟล์แนบทั้งหมดของ ticket (รวมทั้งที่แนบตรงกับ ticket และที่แนบผ่าน comment).
     * ความปลอดภัย: $includeInternal=false จะกรองไฟล์แนบที่ผูกกับ comment ภายในออก — ผู้เรียกต้องส่งค่าตาม role ของผู้ดู
     * @return array<int, array<string, mixed>>
     */
    public function getByTicketId(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT a.*, c.is_internal
                FROM ticket_attachments a
                LEFT JOIN ticket_comments c ON c.id = a.comment_id
                WHERE a.ticket_id = :ticket_id';
        if (!$includeInternal) {
            $sql .= ' AND (a.comment_id IS NULL OR c.is_internal = 0)';
        }
        $sql .= ' ORDER BY a.created_at ASC, a.id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['ticket_id' => $ticketId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * ไฟล์แนบของชุด comment id ที่ระบุ — สำหรับ live-poll feed ที่รู้อยู่แล้วว่า
     * กำลัง render comment ตัวไหน จึงไม่ต้องสแกนไฟล์แนบทั้งหมดของ ticket
     *
     * @param int[] $commentIds
     * @return array<int, array<string, mixed>>
     */
    public function getByCommentIds(array $commentIds, bool $includeInternal): array
    {
        $commentIds = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
        if ($commentIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($commentIds), '?'));
        $sql = "SELECT a.*, c.is_internal
                FROM ticket_attachments a
                INNER JOIN ticket_comments c ON c.id = a.comment_id
                WHERE a.comment_id IN ($placeholders)";
        if (!$includeInternal) {
            $sql .= ' AND c.is_internal = 0';
        }
        $sql .= ' ORDER BY a.created_at ASC, a.id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($commentIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getByCommentId(int $commentId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ticket_attachments WHERE comment_id = :comment_id');
        $stmt->execute(['comment_id' => $commentId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * คืน disk_path ทุกแถวใน DB (สำหรับ orphan cleanup worker)
     * คืนเป็น associative array [path => true] เพื่อ lookup เร็ว
     */
    public function getAllStoredPathsLookup(): array
    {
        $stmt = $this->db->query('SELECT disk_path FROM ticket_attachments');
        $lookup = [];
        foreach ($stmt as $row) {
            $path = trim((string) ($row['disk_path'] ?? ''));
            if ($path !== '') {
                $lookup[$path] = true;
            }
        }

        return $lookup;
    }
}
