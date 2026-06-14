<?php
declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;
use PDOException;
use Throwable;

class AdminRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getUsers(): array
    {
        $stmt = $this->db->query(
            "SELECT u.id, u.username, u.email, u.full_name, u.phone, u.role, u.department_id, u.is_active, d.name AS department_name
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             ORDER BY u.full_name ASC, u.id ASC"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDepartments(): array
    {
        $stmt = $this->db->query(
            'SELECT id, code, name, description, is_active, created_at, updated_at
             FROM departments
             ORDER BY name ASC, id ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getTicketCategories(): array
    {
        $stmt = $this->db->query(
            'SELECT id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at
             FROM ticket_categories
             ORDER BY sort_order ASC, name ASC, id ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function departmentExists(int $departmentId): bool
    {
        $stmt = $this->db->prepare('SELECT EXISTS(SELECT 1 FROM departments WHERE id = :id)');
        $stmt->execute(['id' => $departmentId]);

        return (bool) $stmt->fetchColumn();
    }

    public function createUser(array $payload): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password_hash, full_name, phone, role, department_id, is_active, created_at, updated_at)
                 VALUES (:username, :email, :password_hash, :full_name, :phone, :role, :department_id, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'username' => $payload['username'],
                'email' => $payload['email'],
                'password_hash' => $payload['password_hash'],
                'full_name' => $payload['full_name'],
                'phone' => $payload['phone'] !== '' ? $payload['phone'] : null,
                'role' => $payload['role'],
                'department_id' => $payload['department_id'],
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'username')) {
                    throw new DomainException('ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว');
                }
                if (str_contains($message, 'email')) {
                    throw new DomainException('อีเมลนี้มีอยู่ในระบบแล้ว');
                }
            }

            throw $exception;
        }
    }

    public function updateUser(int $userId, array $payload): void
    {
        try {
            $this->db->beginTransaction();

            $userStmt = $this->db->prepare(
                'SELECT id, role, is_active
                 FROM users
                 WHERE id = :id
                 LIMIT 1
                 FOR UPDATE'
            );
            $userStmt->execute(['id' => $userId]);
            $current = $userStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($current === null) {
                throw new DomainException('ไม่พบผู้ใช้งานที่ต้องการแก้ไข');
            }

            $newRole = (string) $payload['role'];
            $newIsActive = (bool) $payload['is_active'];
            $currentRole = (string) $current['role'];
            $currentIsActive = (bool) $current['is_active'];

            if ($currentRole === 'admin' && $currentIsActive && ($newRole !== 'admin' || !$newIsActive)) {
                $adminStmt = $this->db->query(
                    "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 FOR UPDATE"
                );
                if (count($adminStmt->fetchAll(PDO::FETCH_COLUMN)) <= 1) {
                    throw new DomainException('ไม่สามารถปิดหรือเปลี่ยน role ของผู้ดูแลระบบคนสุดท้ายได้');
                }
            }

            if ($currentRole === 'technician' && ($newRole !== 'technician' || !$newIsActive) && $this->hasOpenTechnicianWork($userId)) {
                throw new DomainException('ผู้ใช้นี้ยังมีงานซ่อมที่กำลังดำเนินการ กรุณามอบหมายงานให้ช่างคนอื่นก่อน');
            }

            if (!$newIsActive && $this->hasOpenRequesterTickets($userId)) {
                throw new DomainException('ผู้ใช้นี้ยังมี Ticket ที่เป็นผู้แจ้ง กรุณาปิดงานให้เรียบร้อยก่อนปิดบัญชี');
            }

            $stmt = $this->db->prepare(
                'UPDATE users
                 SET full_name = :full_name,
                     email = :email,
                     phone = :phone,
                     role = :role,
                     department_id = :department_id,
                     is_active = :is_active,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'full_name' => $payload['full_name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'role' => $newRole,
                'department_id' => $payload['department_id'],
                'is_active' => $newIsActive ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $userId,
            ]);

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    private function hasOpenTechnicianWork(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM tickets
                WHERE assigned_technician_id = :user_id
                  AND status IN ('assigned', 'accepted', 'in_progress')
             )"
        );
        $stmt->execute(['user_id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    private function hasOpenRequesterTickets(int $userId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM tickets
                WHERE requester_id = :user_id
                  AND status NOT IN ('completed', 'rejected', 'cancelled', 'closed')
             )"
        );
        $stmt->execute(['user_id' => $userId]);

        return (bool) $stmt->fetchColumn();
    }

    public function updateDepartment(int $departmentId, array $payload): void
    {
        $stmt = $this->db->prepare(
            'UPDATE departments
             SET code = :code,
                 name = :name,
                 description = :description,
                 is_active = :is_active,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => $payload['description'],
            'is_active' => $payload['is_active'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $departmentId,
        ]);
    }

    public function updateTicketCategory(int $categoryId, array $payload): void
    {
        $stmt = $this->db->prepare(
            'UPDATE ticket_categories
             SET code = :code,
                 name = :name,
                 description = :description,
                 sort_order = :sort_order,
                 is_active = :is_active,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $stmt->execute([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => $payload['description'],
            'sort_order' => $payload['sort_order'],
            'is_active' => $payload['is_active'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $categoryId,
        ]);
    }
}
