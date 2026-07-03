<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\AssetRepository;
use App\Repositories\TicketRepository;
use DomainException;
use Throwable;

class DemoDataService
{
    public function __construct(
        private AdminRepository $admin,
        private AssetRepository $assets,
        private TicketRepository $tickets,
    ) {
    }

    /**
     * Seed a full set of sample data for a fresh install. Idempotent per master-data row
     * (skips duplicates). Orchestrates one seed step per entity — add a new entity by
     * adding a seedX() method and a line here.
     */
    public function load(int $createdByUserId = 0): array
    {
        if ($this->tickets->countAllTickets() > 0) {
            throw new DomainException('ไม่สามารถโหลดข้อมูลตัวอย่าง — มี ticket อยู่ในระบบแล้ว');
        }

        $created = [
            'departments' => $this->seedDepartments(),
            'locations' => $this->seedLocations(),
            'ticket_categories' => $this->seedTicketCategories(),
            'asset_categories' => $this->seedAssetCategories(),
            'priorities' => $this->seedPriorities(),
            'users' => 0,
            'assets' => 0,
            'tickets' => 0,
        ];

        // Build lookups from master data (now includes existing rows)
        $departmentIds = $this->codeMap($this->admin->getDepartments());
        $locationIds = $this->codeMap($this->admin->getLocations());
        $ticketCategoryIds = $this->codeMap($this->admin->getTicketCategories());
        $assetCategoryIds = $this->codeMap($this->admin->getAssetCategories());
        $priorityIds = $this->codeMap($this->admin->getPriorities());

        [$techId, $created['users']] = $this->seedTechnician($departmentIds);
        [$assetIds, $created['assets']] = $this->seedAssets($createdByUserId, $assetCategoryIds, $locationIds);
        $created['tickets'] = $this->seedTickets($createdByUserId, $techId, $locationIds, $assetIds, $ticketCategoryIds, $priorityIds);

        return $created;
    }

    private function seedDepartments(): int
    {
        return $this->seedMasterData([
            ['code' => 'IT', 'name' => 'IT'],
            ['code' => 'FACILITY', 'name' => 'อาคารและสิ่งแวดล้อม'],
            ['code' => 'ADMIN', 'name' => 'บริหาร'],
        ], fn (array $row): int => $this->admin->createDepartment([
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => '',
            'is_active' => true,
        ]));
    }

    private function seedLocations(): int
    {
        return $this->seedMasterData([
            ['code' => 'OFFICE-1F', 'name' => 'สำนักงานชั้น 1'],
            ['code' => 'OFFICE-2F', 'name' => 'สำนักงานชั้น 2'],
            ['code' => 'MEETING', 'name' => 'ห้องประชุม'],
            ['code' => 'SERVER', 'name' => 'ห้องเซิร์ฟเวอร์'],
            ['code' => 'WAREHOUSE', 'name' => 'โกดังสินค้า'],
        ], fn (array $row): int => $this->admin->createLocation([
            'code' => $row['code'],
            'name' => $row['name'],
            'building' => '',
            'floor' => '',
            'room' => '',
            'description' => '',
            'is_active' => true,
        ]));
    }

    private function seedTicketCategories(): int
    {
        return $this->seedMasterData([
            ['code' => 'HARDWARE', 'name' => 'ฮาร์ดแวร์', 'sort' => 1],
            ['code' => 'SOFTWARE', 'name' => 'ซอฟต์แวร์', 'sort' => 2],
            ['code' => 'ELECTRICAL', 'name' => 'ระบบไฟฟ้า', 'sort' => 3],
            ['code' => 'PLUMBING', 'name' => 'ประปา', 'sort' => 4],
        ], fn (array $row): int => $this->admin->createTicketCategory([
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => '',
            'sort_order' => $row['sort'],
            'is_active' => true,
        ]));
    }

    private function seedAssetCategories(): int
    {
        return $this->seedMasterData([
            ['code' => 'COMPUTER', 'name' => 'คอมพิวเตอร์', 'sort' => 1],
            ['code' => 'PRINTER', 'name' => 'เครื่องพิมพ์', 'sort' => 2],
            ['code' => 'AC', 'name' => 'เครื่องปรับอากาศ', 'sort' => 3],
            ['code' => 'LIGHTING', 'name' => 'ระบบไฟส่องสว่าง', 'sort' => 4],
        ], fn (array $row): int => $this->admin->createAssetCategory([
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => '',
            'sort_order' => $row['sort'],
            'is_active' => true,
        ]));
    }

    private function seedPriorities(): int
    {
        return $this->seedMasterData([
            ['code' => 'LOW', 'name' => 'ต่ำ', 'level' => 1, 'color' => 'slate', 'response' => 240, 'resolution' => 2880],
            ['code' => 'MEDIUM', 'name' => 'กลาง', 'level' => 2, 'color' => 'sky', 'response' => 120, 'resolution' => 1440],
            ['code' => 'HIGH', 'name' => 'สูง', 'level' => 3, 'color' => 'amber', 'response' => 60, 'resolution' => 480],
            ['code' => 'URGENT', 'name' => 'ด่วน', 'level' => 4, 'color' => 'rose', 'response' => 15, 'resolution' => 120],
        ], fn (array $row): int => $this->admin->createPriority([
            'code' => $row['code'],
            'name' => $row['name'],
            'level' => $row['level'],
            'color' => $row['color'],
            'response_time_minutes' => $row['response'],
            'resolution_time_minutes' => $row['resolution'],
            'sort_order' => $row['level'],
            'is_active' => true,
        ]));
    }

    /**
     * @param array<string, int> $departmentIds
     * @return array{0: int, 1: int} [technicianUserId, usersCreated]
     */
    private function seedTechnician(array $departmentIds): array
    {
        try {
            $techId = $this->admin->createUser([
                'username' => 'tech_demo',
                'email' => 'tech_demo@example.com',
                'password_hash' => password_hash('demo1234', PASSWORD_BCRYPT),
                'full_name' => 'ช่างเทคนิคตัวอย่าง',
                'phone' => '',
                'role' => 'technician',
                'department_id' => $departmentIds['IT'] ?? null,
                'is_active' => true,
            ]);

            return [$techId, 1];
        } catch (DomainException) {
            // Username/email already exists — best-effort: skip user creation
            return [0, 0];
        }
    }

    /**
     * @param array<string, int> $assetCategoryIds
     * @param array<string, int> $locationIds
     * @return array{0: array<string, int>, 1: int} [assetIdsByCode, assetsCreated]
     */
    private function seedAssets(int $createdByUserId, array $assetCategoryIds, array $locationIds): array
    {
        $assetSpecs = [
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
        $count = 0;
        foreach ($assetSpecs as $spec) {
            $categoryId = $assetCategoryIds[$spec['category']] ?? null;
            $locationId = $locationIds[$spec['location']] ?? null;
            if ($categoryId === null || $locationId === null) {
                continue;
            }

            try {
                $assetId = $this->assets->createAsset([
                    'asset_code' => $spec['code'],
                    'name' => $spec['name'],
                    'serial_number' => '',
                    'asset_category_id' => $categoryId,
                    'department_id' => null,
                    'location_id' => $locationId,
                    'custodian_user_id' => null,
                    'brand' => '',
                    'model' => '',
                    'vendor' => '',
                    'purchase_date' => '',
                    'warranty_expires_at' => '',
                    'status' => 'active',
                    'notes' => '',
                    'generated_by' => $createdByUserId > 0 ? $createdByUserId : null,
                ]);
                $assetIds[$spec['code']] = $assetId;
                $count++;
            } catch (Throwable) {
                // Duplicate asset_code/serial — skip
            }
        }

        return [$assetIds, $count];
    }

    /**
     * @param array<string, int> $locationIds
     * @param array<string, int> $assetIds
     * @param array<string, int> $ticketCategoryIds
     * @param array<string, int> $priorityIds
     */
    private function seedTickets(int $createdByUserId, int $techId, array $locationIds, array $assetIds, array $ticketCategoryIds, array $priorityIds): int
    {
        // Sample tickets need an admin to act as requester + manager.
        if ($createdByUserId <= 0) {
            return 0;
        }

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
                'rated' => false,
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
                'rated' => false,
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
                'rated' => true,
            ],
        ];

        $now = date('Y-m-d H:i:s');
        $count = 0;
        foreach ($samples as $sample) {
            $approvedAt = $sample['approval_status'] === 'approved' ? $now : null;
            $completedAt = $sample['status'] === 'completed' ? $now : null;
            $assignedTech = in_array($sample['status'], ['in_progress', 'completed'], true) && $techId > 0 ? $techId : null;

            $ticketId = $this->tickets->createSeedTicket([
                'ticket_no' => $sample['ticket_no'],
                'title' => $sample['title'],
                'description' => $sample['description'],
                'requester_id' => $createdByUserId,
                'location_id' => $locationIds[$sample['location']] ?? 0,
                'asset_id' => $assetIds[$sample['asset']] ?? null,
                'ticket_category_id' => $ticketCategoryIds[$sample['category']] ?? 0,
                'priority_id' => $priorityIds[$sample['priority']] ?? 0,
                'manager_id' => $createdByUserId,
                'technician_id' => $assignedTech,
                'approval_status' => $sample['approval_status'],
                'status' => $sample['status'],
                'approved_at' => $approvedAt,
                'completed_at' => $completedAt,
            ]);

            if ($ticketId > 0) {
                $count++;
                if ($sample['rated']) {
                    $this->tickets->createSeedRating(
                        $ticketId,
                        $createdByUserId,
                        $techId > 0 ? $techId : null,
                        5,
                        'บริการดีมาก ช่างมาตรงเวลา'
                    );
                }
            }
        }

        return $count;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<string, mixed>): int $creator
     */
    private function seedMasterData(array $rows, callable $creator): int
    {
        $count = 0;
        foreach ($rows as $row) {
            try {
                $creator($row);
                $count++;
            } catch (DomainException) {
                // Already exists — skip silently (idempotent re-run)
            }
        }
        return $count;
    }

    /**
     * Build [code => id] map from a Repository "getX" result that returns rows with 'id' + 'code'.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function codeMap(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            if ($code !== '') {
                $map[$code] = (int) ($row['id'] ?? 0);
            }
        }
        return $map;
    }
}
