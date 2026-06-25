<?php
declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

class DemoDataService
{
    public function __construct(private PDO $db)
    {
    }

    public function load(int $createdByUserId = 0): array
    {
        $ticketCount = (int) $this->db->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        if ($ticketCount > 0) {
            throw new DomainException('ไม่สามารถโหลดข้อมูลตัวอย่าง — มี ticket อยู่ในระบบแล้ว');
        }

        $created = [
            'departments' => 0,
            'locations' => 0,
            'ticket_categories' => 0,
            'asset_categories' => 0,
            'priorities' => 0,
            'users' => 0,
            'assets' => 0,
            'tickets' => 0,
        ];

        try {
            $this->db->beginTransaction();

            // Departments
            $departments = [
                ['code' => 'IT', 'name' => 'IT'],
                ['code' => 'FACILITY', 'name' => 'อาคารและสิ่งแวดล้อม'],
                ['code' => 'ADMIN', 'name' => 'บริหาร'],
            ];
            $departmentIds = $this->bulkInsertReturnIds(
                'INSERT IGNORE INTO departments (code, name, is_active, created_at, updated_at) VALUES (:code, :name, 1, NOW(), NOW())',
                $departments,
                'departments',
                'code'
            );
            $created['departments'] = count($departmentIds);

            // Locations
            $locations = [
                ['code' => 'OFFICE-1F', 'name' => 'สำนักงานชั้น 1'],
                ['code' => 'OFFICE-2F', 'name' => 'สำนักงานชั้น 2'],
                ['code' => 'MEETING', 'name' => 'ห้องประชุม'],
                ['code' => 'SERVER', 'name' => 'ห้องเซิร์ฟเวอร์'],
                ['code' => 'WAREHOUSE', 'name' => 'โกดังสินค้า'],
            ];
            $locationIds = $this->bulkInsertReturnIds(
                'INSERT IGNORE INTO locations (code, name, is_active, created_at, updated_at) VALUES (:code, :name, 1, NOW(), NOW())',
                $locations,
                'locations',
                'code'
            );
            $created['locations'] = count($locationIds);

            // Ticket categories
            $ticketCategories = [
                ['code' => 'HARDWARE', 'name' => 'ฮาร์ดแวร์'],
                ['code' => 'SOFTWARE', 'name' => 'ซอฟต์แวร์'],
                ['code' => 'ELECTRICAL', 'name' => 'ระบบไฟฟ้า'],
                ['code' => 'PLUMBING', 'name' => 'ประปา'],
            ];
            $ticketCategoryIds = $this->bulkInsertReturnIds(
                'INSERT IGNORE INTO ticket_categories (code, name, is_active, created_at, updated_at) VALUES (:code, :name, 1, NOW(), NOW())',
                $ticketCategories,
                'ticket_categories',
                'code'
            );
            $created['ticket_categories'] = count($ticketCategoryIds);

            // Asset categories
            $assetCategories = [
                ['code' => 'COMPUTER', 'name' => 'คอมพิวเตอร์'],
                ['code' => 'PRINTER', 'name' => 'เครื่องพิมพ์'],
                ['code' => 'AC', 'name' => 'เครื่องปรับอากาศ'],
                ['code' => 'LIGHTING', 'name' => 'ระบบไฟส่องสว่าง'],
            ];
            $assetCategoryIds = $this->bulkInsertReturnIds(
                'INSERT IGNORE INTO asset_categories (code, name, is_active, created_at, updated_at) VALUES (:code, :name, 1, NOW(), NOW())',
                $assetCategories,
                'asset_categories',
                'code'
            );
            $created['asset_categories'] = count($assetCategoryIds);

            // Priorities (level UNIQUE, code UNIQUE)
            $priorities = [
                ['code' => 'LOW', 'name' => 'ต่ำ', 'level' => 1, 'color' => 'slate', 'response' => 240, 'resolution' => 2880],
                ['code' => 'MEDIUM', 'name' => 'กลาง', 'level' => 2, 'color' => 'sky', 'response' => 120, 'resolution' => 1440],
                ['code' => 'HIGH', 'name' => 'สูง', 'level' => 3, 'color' => 'amber', 'response' => 60, 'resolution' => 480],
                ['code' => 'URGENT', 'name' => 'ด่วน', 'level' => 4, 'color' => 'rose', 'response' => 15, 'resolution' => 120],
            ];
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO priorities (code, name, level, color, response_time_minutes, resolution_time_minutes, sort_order, is_active, created_at, updated_at)
                 VALUES (:code, :name, :level, :color, :response, :resolution, :level, 1, NOW(), NOW())'
            );
            foreach ($priorities as $row) {
                $stmt->execute($row);
            }
            $priorityIds = $this->codeToIdMap('priorities', array_column($priorities, 'code'));
            $created['priorities'] = count($priorityIds);

            // Sample technician
            $techStmt = $this->db->prepare(
                "INSERT IGNORE INTO users (username, email, password_hash, full_name, role, department_id, is_active, created_at, updated_at)
                 VALUES ('tech_demo', 'tech_demo@example.com', :pw, 'ช่างเทคนิคตัวอย่าง', 'technician', :dept, 1, NOW(), NOW())"
            );
            $techStmt->execute([
                'pw' => password_hash('demo1234', PASSWORD_BCRYPT),
                'dept' => $departmentIds['IT'] ?? null,
            ]);
            $techId = (int) $this->db->lastInsertId();
            if ($techId === 0) {
                $existing = $this->db->prepare("SELECT id FROM users WHERE username = 'tech_demo'");
                $existing->execute();
                $techId = (int) $existing->fetchColumn();
            }
            $created['users'] = $techId > 0 ? 1 : 0;

            // Sample assets
            $assets = [
                ['code' => 'PC-001', 'name' => 'PC HR-01', 'category' => 'COMPUTER', 'location' => 'OFFICE-1F'],
                ['code' => 'PC-002', 'name' => 'PC Finance-01', 'category' => 'COMPUTER', 'location' => 'OFFICE-2F'],
                ['code' => 'PRT-001', 'name' => 'HP LaserJet Pro M404', 'category' => 'PRINTER', 'location' => 'OFFICE-1F'],
                ['code' => 'AC-001', 'name' => 'Daikin Inverter 18000 BTU', 'category' => 'AC', 'location' => 'MEETING'],
                ['code' => 'AC-002', 'name' => 'Mitsubishi 12000 BTU', 'category' => 'AC', 'location' => 'OFFICE-2F'],
                ['code' => 'SRV-001', 'name' => 'Dell PowerEdge R350', 'category' => 'COMPUTER', 'location' => 'SERVER'],
                ['code' => 'LGT-001', 'name' => 'หลอด LED ห้องประชุม', 'category' => 'LIGHTING', 'location' => 'MEETING'],
                ['code' => 'PRT-002', 'name' => 'Brother MFC-L2750DW', 'category' => 'PRINTER', 'location' => 'OFFICE-2F'],
            ];
            $assetIds = [];
            $assetStmt = $this->db->prepare(
                'INSERT IGNORE INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at)
                 VALUES (:code, :name, :category_id, :location_id, "active", NOW(), NOW())'
            );
            foreach ($assets as $asset) {
                $categoryId = $assetCategoryIds[$asset['category']] ?? null;
                $locationId = $locationIds[$asset['location']] ?? null;
                if ($categoryId === null || $locationId === null) {
                    continue;
                }
                $assetStmt->execute([
                    'code' => $asset['code'],
                    'name' => $asset['name'],
                    'category_id' => $categoryId,
                    'location_id' => $locationId,
                ]);
                $insertedId = (int) $this->db->lastInsertId();
                if ($insertedId > 0) {
                    $assetIds[$asset['code']] = $insertedId;
                    // QR token
                    $this->db->prepare(
                        'INSERT INTO asset_qr_tokens (asset_id, token, generated_by, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())'
                    )->execute([$insertedId, bin2hex(random_bytes(16)), $createdByUserId > 0 ? $createdByUserId : null]);
                }
            }
            $created['assets'] = count($assetIds);

            // Sample tickets — require an admin/manager for requester + approver
            if ($createdByUserId > 0) {
                $samples = [
                    [
                        'ticket_no' => 'DEMO-001',
                        'title' => 'PC HR-01 เปิดไม่ติด',
                        'description' => 'กดปุ่ม power แล้วไฟไม่เข้า ลองสลับปลั๊กแล้วก็ไม่ได้',
                        'asset' => 'PC-001',
                        'location' => 'OFFICE-1F',
                        'category' => 'HARDWARE',
                        'priority' => 'HIGH',
                        'status' => 'pending_approval',
                        'approval_status' => 'pending',
                    ],
                    [
                        'ticket_no' => 'DEMO-002',
                        'title' => 'แอร์ห้องประชุมไม่เย็น',
                        'description' => 'เปิดมา 30 นาทียังไม่เย็น เสียงผิดปกติ',
                        'asset' => 'AC-001',
                        'location' => 'MEETING',
                        'category' => 'ELECTRICAL',
                        'priority' => 'MEDIUM',
                        'status' => 'in_progress',
                        'approval_status' => 'approved',
                    ],
                    [
                        'ticket_no' => 'DEMO-003',
                        'title' => 'เครื่องพิมพ์กระดาษติด',
                        'description' => 'มีเสียงดังจาก feeder',
                        'asset' => 'PRT-001',
                        'location' => 'OFFICE-1F',
                        'category' => 'HARDWARE',
                        'priority' => 'LOW',
                        'status' => 'completed',
                        'approval_status' => 'approved',
                    ],
                ];
                $ticketStmt = $this->db->prepare(
                    'INSERT IGNORE INTO tickets (
                        ticket_no, title, description, requester_id, location_id, asset_id, ticket_category_id, priority_id,
                        assigned_manager_id, assigned_technician_id, approval_status, status, channel, impact_level, urgency_level,
                        requested_at, response_due_at, resolution_due_at, approved_at, completed_at, created_at, updated_at
                    ) VALUES (
                        :ticket_no, :title, :description, :requester_id, :location_id, :asset_id, :category_id, :priority_id,
                        :manager_id, :technician_id, :approval_status, :status, "web", "medium", "medium",
                        NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 8 HOUR), :approved_at, :completed_at, NOW(), NOW()
                    )'
                );
                $insertedTickets = 0;
                foreach ($samples as $sample) {
                    $approvedAt = $sample['approval_status'] === 'approved' ? date('Y-m-d H:i:s') : null;
                    $completedAt = $sample['status'] === 'completed' ? date('Y-m-d H:i:s') : null;
                    $assignedTech = in_array($sample['status'], ['in_progress', 'completed'], true) ? $techId : null;
                    $ticketStmt->execute([
                        'ticket_no' => $sample['ticket_no'],
                        'title' => $sample['title'],
                        'description' => $sample['description'],
                        'requester_id' => $createdByUserId,
                        'location_id' => $locationIds[$sample['location']] ?? null,
                        'asset_id' => $assetIds[$sample['asset']] ?? null,
                        'category_id' => $ticketCategoryIds[$sample['category']] ?? null,
                        'priority_id' => $priorityIds[$sample['priority']] ?? null,
                        'manager_id' => $createdByUserId,
                        'technician_id' => $assignedTech,
                        'approval_status' => $sample['approval_status'],
                        'status' => $sample['status'],
                        'approved_at' => $approvedAt,
                        'completed_at' => $completedAt,
                    ]);
                    $ticketId = (int) $this->db->lastInsertId();
                    if ($ticketId > 0) {
                        $insertedTickets++;
                        // Rating for completed
                        if ($sample['status'] === 'completed') {
                            $this->db->prepare(
                                'INSERT IGNORE INTO ticket_ratings (ticket_id, requester_id, technician_id, score, feedback, created_at, updated_at)
                                 VALUES (?, ?, ?, 5, "บริการดีมาก ช่างมาตรงเวลา", NOW(), NOW())'
                            )->execute([$ticketId, $createdByUserId, $techId]);
                        }
                    }
                }
                $created['tickets'] = $insertedTickets;
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        return $created;
    }

    private function bulkInsertReturnIds(string $sql, array $rows, string $table, string $codeColumn): array
    {
        $stmt = $this->db->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
        return $this->codeToIdMap($table, array_column($rows, 'code'));
    }

    private function codeToIdMap(string $table, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->prepare("SELECT id, code FROM $table WHERE code IN ($placeholders)");
        $stmt->execute($codes);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }
        return $map;
    }
}
