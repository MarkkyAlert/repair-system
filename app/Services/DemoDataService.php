<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\AssetRepository;
use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use DomainException;
use PDO;
use RuntimeException;
use Throwable;

class DemoDataService
{
    public function __construct(
        private AdminRepository $admin,
        private AssetRepository $assets,
        private TicketRepository $tickets,
        private TicketReadRepository $reads,
        private PDO $db,
    ) {
    }

    /**
     * Seed a full set of sample data for a fresh install. Idempotent per master-data row
     * (skips duplicates). Orchestrates one seed step per entity — add a new entity by
     * adding a seedX() method and a line here.
     */
    public function load(int $createdByUserId = 0): array
    {
        // ด่านแรก: environment gate — production (ค่าเริ่มต้น) โหลด demo ไม่ได้เด็ดขาด แม้ระบบยังว่าง.
        // คุมทั้ง Setup และ /admin/demo-data/load จากจุดเดียว (template-review F1).
        if (!config('app.allow_demo_data', false)) {
            throw new RuntimeException('การโหลดข้อมูลตัวอย่างถูกปิดใช้งานบนระบบนี้ — ตั้ง ALLOW_DEMO_DATA=true ใน .env เฉพาะรอบทดลอง/เดโม (อย่าเปิดบน production)');
        }

        if ($this->reads->countAllTickets() > 0) {
            throw new DomainException('ไม่สามารถโหลดข้อมูลตัวอย่าง — มี ticket อยู่ในระบบแล้ว');
        }

        // ห่อ seed ทั้งชุดใน transaction เดียว — ถ้าพังกลางทาง rollback หมด ไม่เหลือ demo data ค้างครึ่งๆ.
        // (createAsset/createSeedTicket/createUser เป็น transaction-aware หรือ plain INSERT → participate ได้)
        $startedTransaction = !$this->db->inTransaction();

        try {
            if ($startedTransaction) {
                $this->db->beginTransaction();
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

            [$techIds, $created['users'], $techPassword] = $this->seedTechnician($departmentIds);
            [$assetIds, $created['assets']] = $this->seedAssets($createdByUserId, $assetCategoryIds, $locationIds);
            $created['tickets'] = $this->seedTickets($createdByUserId, $techIds, $departmentIds, $locationIds, $assetIds, $ticketCategoryIds, $priorityIds);

            // surface รหัสช่างตัวอย่างเฉพาะเมื่อสร้างบัญชีใหม่จริง (ถ้ามีอยู่แล้วไม่รู้/ไม่แตะรหัสเดิม)
            if ($created['users'] > 0) {
                $created['demo_technician'] = ['username' => 'tech_demo', 'password' => $techPassword];
            }

            if ($startedTransaction) {
                $this->db->commit();
            }

            return $created;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
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
     * @return array{0: int, 1: int, 2: string} [technicianUserId, usersCreated, plainPassword ('' ถ้าไม่ได้สร้าง)]
     */
    /**
     * สร้างช่างตัวอย่าง 3 คน (ต่างแผนก) เพื่อให้รายงานผลงานช่าง/CSAT-ต่อช่างมีหลายแถว.
     * @return array{0: array<int, int>, 1: int, 2: string} [techIds, created, sharedPassword]
     */
    private function seedTechnician(array $departmentIds): array
    {
        // สุ่มรหัสผ่านต่อการโหลดหนึ่งครั้ง — ห้ามใช้ค่าคงที่ที่เปิดเผยใน source (BAC/known-password).
        // caller (setup/admin) จะ surface รหัสนี้ให้ operator เห็น "ครั้งเดียว" หลังโหลด. ช่างทุกคนใช้รหัสเดียวกัน (demo).
        $plainPassword = bin2hex(random_bytes(8));
        $techs = [
            ['username' => 'tech_demo', 'full_name' => 'สมชาย ช่างเทคนิค', 'dept' => 'IT'],
            ['username' => 'tech_demo2', 'full_name' => 'วิภา ช่างซ่อมบำรุง', 'dept' => 'FACILITY'],
            ['username' => 'tech_demo3', 'full_name' => 'ธนา ช่างไฟฟ้า', 'dept' => 'FACILITY'],
        ];

        $ids = [];
        foreach ($techs as $tech) {
            try {
                $ids[] = $this->admin->createUser([
                    'username' => $tech['username'],
                    'email' => $tech['username'] . '@example.com',
                    'password_hash' => password_hash($plainPassword, PASSWORD_BCRYPT),
                    'full_name' => $tech['full_name'],
                    'phone' => '',
                    'role' => 'technician',
                    'department_id' => $departmentIds[$tech['dept']] ?? null,
                    'is_active' => true,
                ]);
            } catch (DomainException) {
                // Username/email already exists — best-effort: skip (ไม่แตะรหัสเดิม)
            }
        }

        return [$ids, count($ids), $plainPassword];
    }

    /**
     * @param array<string, int> $assetCategoryIds
     * @param array<string, int> $locationIds
     * @return array{0: array<string, int>, 1: int} [assetIdsByCode, assetsCreated]
     */
    private function seedAssets(int $createdByUserId, array $assetCategoryIds, array $locationIds): array
    {
        // ageMonths/warrantyMonths → กระจายอายุ+ประกัน ให้ asset-reliability โชว์ health/MTBF ได้ครบ
        // (เก่า+หมดประกัน = สุขภาพแย่/ควรเปลี่ยน ; ใหม่+ในประกัน = ดี). warranty < age = หมดประกันแล้ว.
        $assetSpecs = [
            ['code' => 'PC-001', 'name' => 'PC HR-01', 'category' => 'COMPUTER', 'location' => 'OFFICE-1F', 'ageMonths' => 48, 'warrantyMonths' => 12],
            ['code' => 'PC-002', 'name' => 'PC Finance-01', 'category' => 'COMPUTER', 'location' => 'OFFICE-2F', 'ageMonths' => 40, 'warrantyMonths' => 12],
            ['code' => 'PRT-001', 'name' => 'HP LaserJet Pro M404', 'category' => 'PRINTER', 'location' => 'OFFICE-1F', 'ageMonths' => 60, 'warrantyMonths' => 12],
            ['code' => 'AC-001', 'name' => 'Daikin Inverter 18000 BTU', 'category' => 'AC', 'location' => 'MEETING', 'ageMonths' => 66, 'warrantyMonths' => 24],
            ['code' => 'AC-002', 'name' => 'Mitsubishi 12000 BTU', 'category' => 'AC', 'location' => 'OFFICE-2F', 'ageMonths' => 22, 'warrantyMonths' => 24],
            ['code' => 'SRV-001', 'name' => 'Dell PowerEdge R350', 'category' => 'COMPUTER', 'location' => 'SERVER', 'ageMonths' => 78, 'warrantyMonths' => 36],
            ['code' => 'LGT-001', 'name' => 'หลอด LED ห้องประชุม', 'category' => 'LIGHTING', 'location' => 'MEETING', 'ageMonths' => 6, 'warrantyMonths' => 12],
            ['code' => 'PRT-002', 'name' => 'Brother MFC-L2750DW', 'category' => 'PRINTER', 'location' => 'OFFICE-2F', 'ageMonths' => 12, 'warrantyMonths' => 24],
        ];
        $assetIds = [];
        $count = 0;
        foreach ($assetSpecs as $spec) {
            $categoryId = $assetCategoryIds[$spec['category']] ?? null;
            $locationId = $locationIds[$spec['location']] ?? null;
            if ($categoryId === null || $locationId === null) {
                continue;
            }

            $purchaseDate = date('Y-m-d', strtotime("-{$spec['ageMonths']} months") ?: time());
            $warrantyExpires = date('Y-m-d', strtotime($purchaseDate . " +{$spec['warrantyMonths']} months") ?: time());

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
                    'purchase_date' => $purchaseDate,
                    'warranty_expires_at' => $warrantyExpires,
                    'status' => 'active',
                    'notes' => '',
                    'generated_by' => $createdByUserId > 0 ? $createdByUserId : null,
                ]);
                $assetIds[$spec['code']] = $assetId;
                $count++;
            } catch (DomainException) {
                // Duplicate asset_code/serial (or QR-token exhaustion) — an EXPECTED, skippable condition.
                // A RuntimeException/PDOException is a real failure: let it propagate so load()'s transaction
                // rolls the whole seed back and the caller sees the error, instead of a silent partial seed.
                // (error-review-4 F4)
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
    private function seedTickets(int $createdByUserId, array $techIds, array $departmentIds, array $locationIds, array $assetIds, array $ticketCategoryIds, array $priorityIds): int
    {
        // Sample tickets need an admin to act as requester + manager, and at least one demo technician.
        if ($createdByUserId <= 0 || $techIds === []) {
            return 0;
        }

        // แผนกผู้แจ้ง หมุนเวียนให้มิติ "แผนก" (hotspot/csat/backlog/sla/reopen) มีหลายแถว ไม่ใช่ 'ไม่ระบุแผนก' ล้วน.
        $deptCodes = ['IT', 'FACILITY', 'ADMIN'];

        // spec ต่อ ticket (กระจายให้ทุกรายงานดูเต็ม): [title, daysAgo, status, cat, loc, pri, asset, techIdx,
        //   labor(min), rating[score,fb]|null, resolveHours|null (null=ยังไม่ปิด), reopen]. breach = resolveHours > 8.
        $specs = [
            // ── ปิดงานแล้ว (resolved/completed/closed) ── มีคะแนน/แรงงาน/SLA/บางตัว breach+reopen
            ['เครื่องพิมพ์ HR ไม่พิมพ์งาน', 84, 'closed', 'HARDWARE', 'OFFICE-1F', 'MEDIUM', 'PRT-001', 0, 45, [5, 'ช่างมาไว แก้จบในครั้งเดียว ประทับใจมาก'], 3, false],
            ['จอคอมการเงินกระพริบถี่', 78, 'completed', 'HARDWARE', 'OFFICE-2F', 'HIGH', 'PC-001', 1, 90, [4, 'โดยรวมดี แต่รอชิ้นส่วนนานไปหน่อย'], 20, false],
            ['แอร์ห้องประชุมใหญ่ไม่เย็น', 70, 'closed', 'ELECTRICAL', 'MEETING', 'HIGH', 'AC-001', 2, 180, [3, 'เย็นแล้วแต่ใช้เวลานาน'], 30, false],
            ['ไฟห้องเก็บของดับทั้งห้อง', 63, 'resolved', 'ELECTRICAL', 'WAREHOUSE', 'LOW', 'LGT-001', 0, 25, [5, 'รวดเร็วมากครับ'], 2, false],
            ['เมาส์และคีย์บอร์ดฝ่าย IT พัง', 58, 'completed', 'HARDWARE', 'OFFICE-1F', 'LOW', 'PC-002', 1, 15, [4, 'เรียบร้อยดี'], 1, false],
            ['เซิร์ฟเวอร์ช้าผิดปกติทั้งวัน', 52, 'completed', 'SOFTWARE', 'SERVER', 'URGENT', 'SRV-001', 2, 240, [2, 'แก้ช้ามาก งานสะดุดทั้งวัน ต้องตามหลายรอบ'], 48, true],
            ['เครื่องพิมพ์ชั้น 2 กระดาษติดบ่อย', 45, 'closed', 'HARDWARE', 'OFFICE-2F', 'MEDIUM', 'PRT-002', 0, 60, [1, 'เปิดซ้ำหลายรอบยังไม่หายขาด ผิดหวัง'], 36, true],
            ['ติดตั้งโปรแกรมบัญชีเวอร์ชันใหม่', 38, 'completed', 'SOFTWARE', 'OFFICE-1F', 'MEDIUM', 'PC-001', 1, 120, [5, 'ติดตั้งเรียบร้อย สอนใช้งานด้วย ดีมาก'], 5, false],
            ['แอร์โรงอาหารมีเสียงดัง', 30, 'resolved', 'ELECTRICAL', 'MEETING', 'MEDIUM', 'AC-002', 2, 75, null, 6, false],
            ['สายแลนหลุดห้องประชุมย่อย', 2, 'closed', 'HARDWARE', 'MEETING', 'LOW', 'PC-002', 0, 20, [4, 'ok ครับ'], 2, false],
            ['จอมอนิเตอร์ห้องเซิร์ฟเวอร์ไม่ติด', 4, 'completed', 'HARDWARE', 'SERVER', 'HIGH', 'SRV-001', 1, 50, [3, 'พอใช้ได้'], 4, false],
            ['ไฟออฟฟิศชั้น 1 บางดวงดับ', 6, 'resolved', 'ELECTRICAL', 'OFFICE-1F', 'LOW', 'LGT-001', 2, 30, null, 3, false],
            // ── งานค้าง (backlog) ── ยังไม่ปิด อายุหลากหลาย (2 ตัวเกิน 30 วัน)
            ['PC ฝ่ายขายเปิดไม่ติด (รอชิ้นส่วน)', 40, 'on_hold', 'HARDWARE', 'OFFICE-2F', 'HIGH', 'PC-002', 0, 30, null, null, false],
            ['แอร์ห้องเซิร์ฟเวอร์ไม่เย็นพอ', 33, 'in_progress', 'ELECTRICAL', 'SERVER', 'URGENT', 'AC-001', 1, 60, null, null, false],
            ['ตั้งค่าเครื่องพิมพ์ใหม่ยังไม่ได้', 15, 'in_progress', 'HARDWARE', 'OFFICE-1F', 'MEDIUM', 'PRT-002', 2, 20, null, null, false],
            ['ขอเพิ่ม RAM เครื่องกราฟิก', 9, 'accepted', 'HARDWARE', 'OFFICE-2F', 'LOW', 'PC-001', 0, 0, null, null, false],
            ['ไฟคลังสินค้ากระพริบ', 5, 'assigned', 'ELECTRICAL', 'WAREHOUSE', 'MEDIUM', 'LGT-001', 1, 0, null, null, false],
            ['เมนบอร์ดเซิร์ฟเวอร์สำรองเสีย', 3, 'on_hold', 'HARDWARE', 'SERVER', 'HIGH', 'SRV-001', 2, 0, null, null, false],
            // ── terminal ที่ไม่นับเป็นปิดงาน/ค้าง ──
            ['ขอย้ายปลั๊กไฟ (ผู้แจ้งยกเลิกเอง)', 20, 'cancelled', 'ELECTRICAL', 'OFFICE-1F', 'LOW', 'LGT-001', 0, 0, null, null, false],
            ['แจ้งผิดแผนก (ปฏิเสธ)', 14, 'rejected', 'SOFTWARE', 'OFFICE-2F', 'LOW', 'PC-002', 0, 0, null, null, false],
        ];

        $techCount = count($techIds);
        $count = 0;
        $index = 0;
        foreach ($specs as [$title, $daysAgo, $status, $cat, $loc, $pri, $asset, $techIdx, $labor, $rating, $resolveHours, $reopen]) {
            $index++;
            $reqTs = strtotime("-{$daysAgo} days -" . (($index % 8) + 1) . ' hours') ?: time();
            $requestedAt = date('Y-m-d H:i:s', $reqTs);
            $isDone = $resolveHours !== null;
            $resolvedAt = $isDone ? date('Y-m-d H:i:s', $reqTs + $resolveHours * 3600) : null;
            $completedAt = in_array($status, ['completed', 'closed'], true) ? $resolvedAt : null;
            $isTerminalReject = in_array($status, ['rejected', 'cancelled'], true);
            $approvalStatus = $status === 'rejected' ? 'rejected' : 'approved';
            $tech = $techIds[$techIdx % $techCount] ?? null;
            $deptId = $departmentIds[$deptCodes[$index % count($deptCodes)]] ?? null;
            $responseDue = date('Y-m-d H:i:s', $reqTs + 3600);
            $resolutionDue = date('Y-m-d H:i:s', $reqTs + 8 * 3600);

            $ticketId = $this->tickets->createSeedTicket([
                'ticket_no' => sprintf('DEMO-%03d', $index),
                'title' => $title,
                'description' => $title . ' — รายละเอียดตัวอย่างสำหรับสาธิตระบบ',
                'requester_id' => $createdByUserId,
                'requester_department_id' => $deptId,
                'location_id' => $locationIds[$loc] ?? 0,
                'asset_id' => $assetIds[$asset] ?? null,
                'ticket_category_id' => $ticketCategoryIds[$cat] ?? 0,
                'priority_id' => $priorityIds[$pri] ?? 0,
                'manager_id' => $createdByUserId,
                'technician_id' => $isTerminalReject ? null : $tech,
                'approval_status' => $approvalStatus,
                'status' => $status,
                'requested_at' => $requestedAt,
                'response_due_at' => $responseDue,
                'resolution_due_at' => $resolutionDue,
                'approved_at' => $status === 'rejected' ? null : date('Y-m-d H:i:s', $reqTs + 1800),
                'resolved_at' => $resolvedAt,
                'completed_at' => $completedAt,
            ]);

            if ($ticketId <= 0) {
                continue;
            }
            $count++;

            if ($isTerminalReject) {
                continue; // ไม่นับ SLA/แรงงาน/คะแนน ให้ terminal ที่ไม่ใช่งานจริง
            }

            // Work order + labor (ช่างที่ลงมือทำ)
            if ($tech !== null && in_array($status, ['in_progress', 'on_hold', 'accepted', 'resolved', 'completed', 'closed'], true)) {
                $woStatus = $isDone ? 'completed' : ($status === 'in_progress' ? 'in_progress' : 'assigned');
                $this->tickets->createSeedWorkOrder($ticketId, $tech, $createdByUserId, $woStatus, $labor, $requestedAt, $isDone ? $resolvedAt : null);
            }

            // SLA tracks: response (met) + resolution (met/breached เมื่อปิด, pending เมื่อยังค้าง)
            $this->tickets->createSeedSlaTrack($ticketId, 'response', $responseDue, date('Y-m-d H:i:s', $reqTs + 1200), null, 'met');
            if ($isDone) {
                $breach = $resolveHours > 8;
                $this->tickets->createSeedSlaTrack(
                    $ticketId,
                    'resolution',
                    $resolutionDue,
                    $breach ? null : $resolvedAt,
                    $breach ? date('Y-m-d H:i:s', $reqTs + ($resolveHours + 1) * 3600) : null,
                    $breach ? 'breached' : 'met'
                );
            } else {
                $this->tickets->createSeedSlaTrack($ticketId, 'resolution', $resolutionDue, null, null, 'pending');
            }

            // Activity logs: resolved (+ reopened บางตัว → reopen rate ไม่เป็น 0)
            if ($isDone) {
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'ticket_resolved', 'in_progress', 'resolved', (string) $resolvedAt);
                if ($reopen) {
                    $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_reopened', 'resolved', 'assigned', date('Y-m-d H:i:s', strtotime((string) $resolvedAt) + 2 * 86400));
                }
            }

            // Rating (คะแนน + ความคิดเห็น) — มี ≤2★ ให้เห็นจุดที่ต้องปรับปรุง
            if ($rating !== null) {
                $this->tickets->createSeedRating($ticketId, $createdByUserId, $tech, (int) $rating[0], (string) $rating[1]);
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
