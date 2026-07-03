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

    public function getAssetCategories(): array
    {
        $stmt = $this->db->query(
            'SELECT id, parent_id, code, name, description, sort_order, is_active, created_at, updated_at
             FROM asset_categories
             ORDER BY sort_order ASC, name ASC, id ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getLocations(): array
    {
        $stmt = $this->db->query(
            'SELECT id, code, name, building, floor, room, description, is_active, created_at, updated_at
             FROM locations
             ORDER BY name ASC, id ASC'
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPriorities(): array
    {
        $stmt = $this->db->query(
            'SELECT id, code, name, level, color, response_time_minutes, resolution_time_minutes, sort_order, is_active, created_at, updated_at
             FROM priorities
             ORDER BY sort_order ASC, level ASC, id ASC'
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
            if (is_duplicate_key_error($exception)) {
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

            try {
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
            } catch (PDOException $exception) {
                if (is_duplicate_key_error($exception)) {
                    $message = strtolower($exception->getMessage());
                    if (str_contains($message, 'email')) {
                        throw new DomainException('อีเมลนี้มีอยู่ในระบบแล้ว');
                    }
                    if (str_contains($message, 'username')) {
                        throw new DomainException('ชื่อผู้ใช้นี้มีอยู่ในระบบแล้ว');
                    }
                }
                throw $exception;
            }

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
        try {
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

            if ($stmt->rowCount() === 0 && !$this->recordExists('departments', $departmentId)) {
                throw new DomainException('ไม่พบแผนกที่ต้องการแก้ไข');
            }
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสแผนกนี้มีอยู่แล้ว', 'ชื่อแผนกนี้มีอยู่แล้ว');
        }
    }

    public function createDepartment(array $payload): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO departments (code, name, description, is_active, created_at, updated_at)
                 VALUES (:code, :name, :description, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสแผนกนี้มีอยู่แล้ว', 'ชื่อแผนกนี้มีอยู่แล้ว');
        }
    }

    public function deleteDepartment(int $departmentId): void
    {
        try {
            $this->db->beginTransaction();
            $this->lockRecord('departments', $departmentId, 'ไม่พบแผนกที่ต้องการลบ');

            if ($this->departmentInUse($departmentId)) {
                throw new DomainException('แผนกนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            $stmt = $this->db->prepare('DELETE FROM departments WHERE id = :id');
            $stmt->execute(['id' => $departmentId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new DomainException('แผนกนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            throw $exception;
        }
    }

    public function updateTicketCategory(int $categoryId, array $payload): void
    {
        try {
            if ($this->masterValueExists('ticket_categories', 'name', (string) $payload['name'], $categoryId)) {
                throw new DomainException('ชื่อหมวดหมู่งานนี้มีอยู่แล้ว');
            }

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

            if ($stmt->rowCount() === 0 && !$this->recordExists('ticket_categories', $categoryId)) {
                throw new DomainException('ไม่พบหมวดหมู่งานที่ต้องการแก้ไข');
            }
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสหมวดหมู่งานนี้มีอยู่แล้ว', 'ชื่อหมวดหมู่งานนี้มีอยู่แล้ว');
        }
    }

    public function createTicketCategory(array $payload): int
    {
        try {
            if ($this->masterValueExists('ticket_categories', 'name', (string) $payload['name'])) {
                throw new DomainException('ชื่อหมวดหมู่งานนี้มีอยู่แล้ว');
            }

            $stmt = $this->db->prepare(
                'INSERT INTO ticket_categories (parent_id, code, name, description, sort_order, is_active, created_at, updated_at)
                 VALUES (NULL, :code, :name, :description, :sort_order, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'sort_order' => $payload['sort_order'],
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสหมวดหมู่งานนี้มีอยู่แล้ว', 'ชื่อหมวดหมู่งานนี้มีอยู่แล้ว');
        }
    }

    public function deleteTicketCategory(int $categoryId): void
    {
        try {
            $this->db->beginTransaction();
            $this->lockRecord('ticket_categories', $categoryId, 'ไม่พบหมวดหมู่งานที่ต้องการลบ');

            if ($this->ticketCategoryInUse($categoryId)) {
                throw new DomainException('หมวดหมู่งานนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            $settingStmt = $this->db->prepare('DELETE FROM system_settings WHERE setting_key = :setting_key');
            $settingStmt->execute(['setting_key' => 'category_sla_' . $categoryId]);

            $stmt = $this->db->prepare('DELETE FROM ticket_categories WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new DomainException('หมวดหมู่งานนี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            throw $exception;
        }
    }

    public function createAssetCategory(array $payload): int
    {
        try {
            if ($this->masterValueExists('asset_categories', 'name', (string) $payload['name'])) {
                throw new DomainException('ชื่อหมวดหมู่ Asset นี้มีอยู่แล้ว');
            }

            $stmt = $this->db->prepare(
                'INSERT INTO asset_categories (parent_id, code, name, description, sort_order, is_active, created_at, updated_at)
                 VALUES (NULL, :code, :name, :description, :sort_order, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'sort_order' => $payload['sort_order'],
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสหมวดหมู่ Asset นี้มีอยู่แล้ว', 'ชื่อหมวดหมู่ Asset นี้มีอยู่แล้ว');
        }
    }

    public function updateAssetCategory(int $categoryId, array $payload): void
    {
        try {
            if ($this->masterValueExists('asset_categories', 'name', (string) $payload['name'], $categoryId)) {
                throw new DomainException('ชื่อหมวดหมู่ Asset นี้มีอยู่แล้ว');
            }

            $stmt = $this->db->prepare(
                'UPDATE asset_categories
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

            if ($stmt->rowCount() === 0 && !$this->recordExists('asset_categories', $categoryId)) {
                throw new DomainException('ไม่พบหมวดหมู่ Asset ที่ต้องการแก้ไข');
            }
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสหมวดหมู่ Asset นี้มีอยู่แล้ว', 'ชื่อหมวดหมู่ Asset นี้มีอยู่แล้ว');
        }
    }

    public function deleteAssetCategory(int $categoryId): void
    {
        try {
            $this->db->beginTransaction();
            $this->lockRecord('asset_categories', $categoryId, 'ไม่พบหมวดหมู่ Asset ที่ต้องการลบ');

            if ($this->assetCategoryInUse($categoryId)) {
                throw new DomainException('หมวดหมู่ Asset นี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            $stmt = $this->db->prepare('DELETE FROM asset_categories WHERE id = :id');
            $stmt->execute(['id' => $categoryId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new DomainException('หมวดหมู่ Asset นี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            throw $exception;
        }
    }

    public function createLocation(array $payload): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO locations (code, name, building, floor, room, description, is_active, created_at, updated_at)
                 VALUES (:code, :name, :building, :floor, :room, :description, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'building' => $payload['building'] !== '' ? $payload['building'] : null,
                'floor' => $payload['floor'] !== '' ? $payload['floor'] : null,
                'room' => $payload['room'] !== '' ? $payload['room'] : null,
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสสถานที่นี้มีอยู่แล้ว', 'ชื่อสถานที่นี้มีอยู่แล้ว');
        }
    }

    public function updateLocation(int $locationId, array $payload): void
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE locations
                 SET code = :code,
                     name = :name,
                     building = :building,
                     floor = :floor,
                     room = :room,
                     description = :description,
                     is_active = :is_active,
                     updated_at = :updated_at
                 WHERE id = :id'
            );
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'building' => $payload['building'] !== '' ? $payload['building'] : null,
                'floor' => $payload['floor'] !== '' ? $payload['floor'] : null,
                'room' => $payload['room'] !== '' ? $payload['room'] : null,
                'description' => $payload['description'] !== '' ? $payload['description'] : null,
                'is_active' => $payload['is_active'] ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $locationId,
            ]);

            if ($stmt->rowCount() === 0 && !$this->recordExists('locations', $locationId)) {
                throw new DomainException('ไม่พบสถานที่ที่ต้องการแก้ไข');
            }
        } catch (PDOException $exception) {
            $this->throwFriendlyUniqueViolation($exception, 'รหัสสถานที่นี้มีอยู่แล้ว', 'ชื่อสถานที่นี้มีอยู่แล้ว');
        }
    }

    public function deleteLocation(int $locationId): void
    {
        try {
            $this->db->beginTransaction();
            $this->lockRecord('locations', $locationId, 'ไม่พบสถานที่ที่ต้องการลบ');

            if ($this->locationInUse($locationId)) {
                throw new DomainException('สถานที่นี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            $stmt = $this->db->prepare('DELETE FROM locations WHERE id = :id');
            $stmt->execute(['id' => $locationId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new DomainException('สถานที่นี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            throw $exception;
        }
    }

    public function createPriority(array $payload): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO priorities (code, name, level, color, response_time_minutes, resolution_time_minutes, sort_order, is_active, created_at, updated_at)
                 VALUES (:code, :name, :level, :color, :response_time_minutes, :resolution_time_minutes, :sort_order, :is_active, :created_at, :updated_at)'
            );
            $createdAt = date('Y-m-d H:i:s');
            $stmt->execute([
                'code' => $payload['code'],
                'name' => $payload['name'],
                'level' => $payload['level'],
                'color' => $payload['color'] !== '' ? $payload['color'] : null,
                'response_time_minutes' => $payload['response_time_minutes'],
                'resolution_time_minutes' => $payload['resolution_time_minutes'],
                'sort_order' => $payload['sort_order'],
                'is_active' => $payload['is_active'] ? 1 : 0,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $exception) {
            if (is_duplicate_key_error($exception)) {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'code')) {
                    throw new DomainException('รหัส Priority นี้มีอยู่แล้ว');
                }
                if (str_contains($message, 'level')) {
                    throw new DomainException('ระดับ Priority นี้ถูกใช้งานแล้ว');
                }
                throw new DomainException('Priority ซ้ำกับข้อมูลที่มีอยู่');
            }
            throw $exception;
        }
    }

    public function deletePriority(int $priorityId): void
    {
        try {
            $this->db->beginTransaction();
            $this->lockRecord('priorities', $priorityId, 'ไม่พบ Priority ที่ต้องการลบ');

            if ($this->priorityInUse($priorityId)) {
                throw new DomainException('Priority นี้ถูกใช้งานในรายการแจ้งซ่อมแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            $stmt = $this->db->prepare('DELETE FROM priorities WHERE id = :id');
            $stmt->execute(['id' => $priorityId]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && (string) $exception->getCode() === '23000') {
                throw new DomainException('Priority นี้ถูกใช้งานแล้ว กรุณาปิดใช้งานแทนการลบ');
            }

            throw $exception;
        }
    }

    public function updatePriority(int $priorityId, array $payload): void
    {
        $stmt = $this->db->prepare(
            'UPDATE priorities
             SET name = :name,
                 color = :color,
                 response_time_minutes = :response_time_minutes,
                 resolution_time_minutes = :resolution_time_minutes,
                 sort_order = :sort_order,
                 is_active = :is_active,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        try {
            $stmt->execute([
                'name' => $payload['name'],
                'color' => $payload['color'] !== '' ? $payload['color'] : null,
                'response_time_minutes' => $payload['response_time_minutes'],
                'resolution_time_minutes' => $payload['resolution_time_minutes'],
                'sort_order' => $payload['sort_order'],
                'is_active' => $payload['is_active'] ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'id' => $priorityId,
            ]);
        } catch (PDOException $exception) {
            if (is_duplicate_key_error($exception)) {
                $message = strtolower($exception->getMessage());
                if (str_contains($message, 'code')) {
                    throw new DomainException('รหัส Priority นี้มีอยู่แล้ว');
                }
                if (str_contains($message, 'level')) {
                    throw new DomainException('ระดับ Priority นี้ถูกใช้งานแล้ว');
                }
            }
            throw $exception;
        }

        if ($stmt->rowCount() === 0 && !$this->recordExists('priorities', $priorityId)) {
            throw new DomainException('ไม่พบ Priority ที่ต้องการแก้ไข');
        }
    }

    private function locationInUse(int $locationId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM assets WHERE location_id = :asset_location_id)
                OR EXISTS(SELECT 1 FROM tickets WHERE location_id = :ticket_location_id)'
        );
        $stmt->execute([
            'asset_location_id' => $locationId,
            'ticket_location_id' => $locationId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function priorityInUse(int $priorityId): bool
    {
        $stmt = $this->db->prepare('SELECT EXISTS(SELECT 1 FROM tickets WHERE priority_id = :priority_id)');
        $stmt->execute(['priority_id' => $priorityId]);

        return (bool) $stmt->fetchColumn();
    }

    private function departmentInUse(int $departmentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM users WHERE department_id = :user_department_id)
                OR EXISTS(SELECT 1 FROM assets WHERE department_id = :asset_department_id)
                OR EXISTS(SELECT 1 FROM tickets WHERE requester_department_id = :ticket_department_id)'
        );
        $stmt->execute([
            'user_department_id' => $departmentId,
            'asset_department_id' => $departmentId,
            'ticket_department_id' => $departmentId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function ticketCategoryInUse(int $categoryId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM tickets WHERE ticket_category_id = :ticket_category_id)
                OR EXISTS(SELECT 1 FROM ticket_categories WHERE parent_id = :child_category_id)'
        );
        $stmt->execute([
            'ticket_category_id' => $categoryId,
            'child_category_id' => $categoryId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function assetCategoryInUse(int $categoryId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM assets WHERE asset_category_id = :asset_category_id)
                OR EXISTS(SELECT 1 FROM asset_categories WHERE parent_id = :child_category_id)'
        );
        $stmt->execute([
            'asset_category_id' => $categoryId,
            'child_category_id' => $categoryId,
        ]);

        return (bool) $stmt->fetchColumn();
    }

    private function masterValueExists(string $table, string $column, string $value, ?int $excludeId = null): bool
    {
        $sql = "SELECT EXISTS(SELECT 1 FROM {$table} WHERE {$column} = :value";
        $params = ['value' => $value];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeId;
        }
        $sql .= ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    private function recordExists(string $table, int $id): bool
    {
        $stmt = $this->db->prepare("SELECT EXISTS(SELECT 1 FROM {$table} WHERE id = :id)");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    private function lockRecord(string $table, int $id, string $notFoundMessage): void
    {
        $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE id = :id LIMIT 1 FOR UPDATE");
        $stmt->execute(['id' => $id]);
        if ($stmt->fetchColumn() === false) {
            throw new DomainException($notFoundMessage);
        }
    }

    private function throwFriendlyUniqueViolation(PDOException $exception, string $codeMessage, string $nameMessage): void
    {
        if (is_duplicate_key_error($exception)) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'code')) {
                throw new DomainException($codeMessage);
            }
            if (str_contains($message, 'name')) {
                throw new DomainException($nameMessage);
            }
        }

        throw $exception;
    }
}
