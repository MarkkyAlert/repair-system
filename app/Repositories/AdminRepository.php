<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

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

    public function updateUser(int $userId, array $payload): void
    {
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
            'role' => $payload['role'],
            'department_id' => $payload['department_id'],
            'is_active' => $payload['is_active'] ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
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
