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
     * seed ชุดข้อมูลตัวอย่างทั้งชุดสำหรับติดตั้งใหม่. รันซ้ำได้ปลอดภัยเพราะ row ของ master-data ที่ซ้ำจะถูกข้าม
     * (idempotent). seed ทีละ entity เรียงตามลำดับ — อยากเพิ่ม entity ใหม่ก็เขียน method seedX()
     * แล้วมาเพิ่มบรรทัดตรงนี้.
     */
    public function load(int $createdByUserId = 0): array
    {
        // ด่านแรก: environment gate — production (ค่าเริ่มต้น) โหลด demo ไม่ได้เด็ดขาด ถึงระบบจะยังว่างก็ตาม.
        // คุมทั้ง Setup และ /admin/demo-data/load จากจุดเดียว.
        // เป็นการบล็อกตามนโยบายที่ตั้งใจไว้ (บอก operator ให้ไปสลับ flag เอง) เลยใช้ DomainException ที่ flash ขึ้นให้เห็น ไม่ใช่
        // RuntimeException แบบระบบพังที่ catch ควรเก็บ log.
        if (!config('app.allow_demo_data', false)) {
            throw new DomainException('การโหลดข้อมูลตัวอย่างถูกปิดใช้งานบนระบบนี้ — ตั้ง ALLOW_DEMO_DATA=true ใน .env เฉพาะรอบทดลอง/เดโม (อย่าเปิดบน production)');
        }

        if ($this->reads->countAllTickets() > 0) {
            throw new DomainException('ไม่สามารถโหลดข้อมูลตัวอย่าง — มีงานแจ้งซ่อมอยู่ในระบบแล้ว');
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

            // สร้างตาราง lookup จาก master data (ตอนนี้รวม row ที่มีอยู่เดิมด้วย)
            $departmentIds = $this->codeMap($this->admin->getDepartments());
            $locationIds = $this->codeMap($this->admin->getLocations());
            $ticketCategoryIds = $this->codeMap($this->admin->getTicketCategories());
            $assetCategoryIds = $this->codeMap($this->admin->getAssetCategories());
            $priorityIds = $this->codeMap($this->admin->getPriorities());

            [$staffIds, $created['users'], $staffPassword] = $this->seedTechnician($departmentIds);
            [$assetIds, $created['assets']] = $this->seedAssets($createdByUserId, $assetCategoryIds, $locationIds);
            $created['tickets'] = $this->seedTickets($createdByUserId, $staffIds, $departmentIds, $locationIds, $assetIds, $ticketCategoryIds, $priorityIds);
            $created['guest_requests'] = $this->seedGuestRequests($createdByUserId, $assetIds);

            // surface รหัสบัญชีตัวอย่างเฉพาะเมื่อสร้างบัญชีใหม่จริง (ถ้ามีอยู่แล้วไม่รู้/ไม่แตะรหัสเดิม).
            // demo_technician คงไว้ตามเดิมเพื่อไม่ให้ผู้เรียกเดิมพัง; demo_accounts เพิ่มมาให้บอกครบทุกบทบาท เพราะ
            // คนที่เพิ่งติดตั้งต้องล็อกอินเป็นผู้แจ้งและหัวหน้าได้ ไม่ใช่รู้แค่บัญชีช่าง
            if ($created['users'] > 0) {
                $created['demo_technician'] = ['username' => 'tech_demo', 'password' => $staffPassword];
                $created['demo_accounts'] = [
                    'password' => $staffPassword,
                    'usernames' => ['ผู้แจ้ง' => 'user_demo', 'หัวหน้างาน' => 'mgr_demo', 'ช่าง' => 'tech_demo'],
                ];
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
            // ปิดใช้งานแล้ว (ย้ายออกไปแล้ว) แต่ยังมีประวัติงานเก่าผูกอยู่ — ทุกองค์กรมีของแบบนี้ และเป็นจุดที่ระบบ
            // ต้องยังแสดงชื่อในรายงานย้อนหลังได้ ขณะที่ไม่ให้เลือกตอนแจ้งใหม่
            ['code' => 'OFFICE-OLD', 'name' => 'สำนักงานเก่า (ปิดใช้งาน)', 'active' => false],
        ], fn (array $row): int => $this->admin->createLocation([
            'code' => $row['code'],
            'name' => $row['name'],
            'building' => '',
            'floor' => '',
            'room' => '',
            'description' => '',
            'is_active' => (bool) ($row['active'] ?? true),
        ]));
    }

    private function seedTicketCategories(): int
    {
        return $this->seedMasterData([
            ['code' => 'HARDWARE', 'name' => 'ฮาร์ดแวร์', 'sort' => 1],
            ['code' => 'SOFTWARE', 'name' => 'ซอฟต์แวร์', 'sort' => 2],
            ['code' => 'ELECTRICAL', 'name' => 'ระบบไฟฟ้า', 'sort' => 3],
            ['code' => 'PLUMBING', 'name' => 'ประปา', 'sort' => 4],
            // เลิกใช้แล้ว — เก็บไว้ให้เห็นว่าหมวดที่ปิดใช้งานยังอ่านรายงานย้อนหลังได้ แต่เลือกตอนแจ้งใหม่ไม่ได้
            ['code' => 'FAX', 'name' => 'เครื่องแฟกซ์ (เลิกใช้แล้ว)', 'sort' => 9, 'active' => false],
        ], fn (array $row): int => $this->admin->createTicketCategory([
            'code' => $row['code'],
            'name' => $row['name'],
            'description' => '',
            'sort_order' => $row['sort'],
            'is_active' => (bool) ($row['active'] ?? true),
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
            ['code' => 'MEDIUM', 'name' => 'ปานกลาง', 'level' => 2, 'color' => 'sky', 'response' => 120, 'resolution' => 1440],
            ['code' => 'HIGH', 'name' => 'สูง', 'level' => 3, 'color' => 'amber', 'response' => 60, 'resolution' => 480],
            ['code' => 'URGENT', 'name' => 'เร่งด่วน', 'level' => 4, 'color' => 'rose', 'response' => 15, 'resolution' => 120],
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
     * คำขอจาก QR ของคนนอกที่ไม่มีบัญชี — สองใบ: ใบหนึ่งยังรอผู้ดูแลตรวจ (จะได้เห็นกล่องคำขอมีของจริงให้กด) และอีก
     * ใบหนึ่งถูกแปลงเป็นใบงานไปแล้ว (จะได้เห็นว่าปลายทางของ QR หน้าตาเป็นยังไง) เส้นทางนี้คือจุดขายที่คนซื้อชอบที่สุด
     * เวลาเดโม แต่เดิมกล่องคำขอว่างเปล่าเสมอ จึงไม่มีอะไรให้ดูเลย
     *
     * @param array<string, int> $assetIds
     */
    private function seedGuestRequests(int $createdByUserId, array $assetIds): int
    {
        if ($createdByUserId <= 0) {
            return 0;
        }

        // ผูกกับใบงานที่ demo สร้างไว้ใบหนึ่ง เพื่อให้ "คำขอที่แปลงแล้ว" ชี้ไปที่งานจริงที่เปิดดูต่อได้
        $convertedTicketId = (int) ($this->db->query("SELECT id FROM tickets WHERE ticket_no = 'DEMO-002' LIMIT 1")->fetchColumn() ?: 0);
        $printerAssetId = $assetIds['PRT-001'] ?? null;

        $rows = [
            [
                'no' => 'GR-DEMO-0001',
                'name' => 'คุณสมศรี (แม่บ้าน)',
                'phone' => '081-234-5678',
                'title' => 'หลอดไฟหน้าห้องน้ำชั้น 1 กระพริบ',
                'desc' => "หลอดไฟตรงทางเดินหน้าห้องน้ำกระพริบมาสองวันแล้ว ตอนกลางคืนมองไม่ค่อยเห็น\nกลัวว่าคนเดินจะสะดุด รบกวนช่วยเปลี่ยนให้หน่อยค่ะ",
                'status' => 'new',
                'ticket' => null,
                'daysAgo' => 1,
            ],
            [
                'no' => 'GR-DEMO-0002',
                'name' => 'คุณวิชัย (พนักงานส่งของ)',
                'phone' => '089-876-5432',
                'title' => 'เครื่องพิมพ์ใบเสร็จหน้าเคาน์เตอร์กระดาษติด',
                'desc' => 'กระดาษติดในเครื่องพิมพ์ ดึงออกเองแล้วแต่ยังพิมพ์ไม่ออก มีลูกค้ารอรับใบเสร็จอยู่',
                'status' => $convertedTicketId > 0 ? 'converted' : 'new',
                'ticket' => $convertedTicketId > 0 ? $convertedTicketId : null,
                'daysAgo' => 9,
            ],
        ];

        $stmt = $this->db->prepare(
            'INSERT INTO guest_ticket_requests
                (request_no, asset_id, guest_name, guest_phone, title, description, status, converted_ticket_id, reviewed_by, reviewed_at, created_at)
             VALUES (:no, :asset_id, :name, :phone, :title, :description, :status, :ticket, :reviewed_by, :reviewed_at, :created_at)'
        );

        $created = 0;
        foreach ($rows as $row) {
            $createdAt = date('Y-m-d H:i:s', strtotime('-' . $row['daysAgo'] . ' days'));
            $stmt->execute([
                'no' => $row['no'],
                'asset_id' => $printerAssetId,
                'name' => $row['name'],
                'phone' => $row['phone'],
                'title' => $row['title'],
                'description' => $row['desc'],
                'status' => $row['status'],
                'ticket' => $row['ticket'],
                'reviewed_by' => $row['ticket'] !== null ? $createdByUserId : null,
                'reviewed_at' => $row['ticket'] !== null ? $createdAt : null,
                'created_at' => $createdAt,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * สร้างบัญชีตัวอย่างให้ครบทุกบทบาทที่ผู้ซื้อต้องลอง — ช่าง 3 คน (ต่างแผนก เพื่อให้รายงานผลงานช่าง/CSAT-ต่อช่าง
     * มีหลายแถว) หัวหน้า 1 คน และผู้แจ้ง 2 คน (ต่างแผนก เพื่อให้มิติ "แผนก" ในรายงานมีของจริง).
     *
     * @param array<string, int> $departmentIds
     * @return array{0: array{technician: list<int>, manager: list<int>, requester: list<int>}, 1: int, 2: string}
     *         [idsByRole, usersCreated, sharedPassword]
     */
    private function seedTechnician(array $departmentIds): array
    {
        // สุ่มรหัสผ่านใหม่ทุกครั้งที่โหลด — ห้าม hardcode ค่าคงที่ไว้ใน source เพราะใครเปิด source ก็รู้รหัสทันที.
        // caller (setup/admin) จะโชว์รหัสนี้ให้ operator เห็นครั้งเดียวหลังโหลด. บัญชี demo ทุกใบใช้รหัสเดียวกัน.
        //
        // ต้องมีครบทั้ง 4 บทบาท ไม่ใช่แค่ช่าง: คนที่เพิ่งติดตั้งจะกดดูรอบแรกด้วยบัญชีที่ demo แถมมาเท่านั้น ถ้ามีแต่
        // แอดมินกับช่าง เขาจะไม่มีทางเห็นสองหน้าจอที่พนักงานส่วนใหญ่ใช้จริง — หน้าแจ้งซ่อมบนมือถือของผู้แจ้ง และคิว
        // อนุมัติของหัวหน้า ซึ่งเป็นสองอย่างที่ตัดสินใจซื้อ. ผู้แจ้งมีสองคนคนละแผนก เพื่อให้รายงานแยกตามแผนกมีของจริง
        // ให้ดู แทนที่จะเป็นชื่อเดียวทั้งตาราง
        $plainPassword = bin2hex(random_bytes(8));
        $staff = [
            ['username' => 'tech_demo', 'full_name' => 'สมชาย ช่างเทคนิค', 'dept' => 'IT', 'role' => 'technician'],
            ['username' => 'tech_demo2', 'full_name' => 'วิภา ช่างซ่อมบำรุง', 'dept' => 'FACILITY', 'role' => 'technician'],
            ['username' => 'tech_demo3', 'full_name' => 'ธนา ช่างไฟฟ้า', 'dept' => 'FACILITY', 'role' => 'technician'],
            ['username' => 'mgr_demo', 'full_name' => 'สุภาวดี หัวหน้าฝ่ายอาคาร', 'dept' => 'FACILITY', 'role' => 'manager'],
            ['username' => 'user_demo', 'full_name' => 'ปรียา ธุรการ', 'dept' => 'OFFICE', 'role' => 'requester'],
            ['username' => 'user_demo2', 'full_name' => 'ณัฐพล ฝ่ายผลิต', 'dept' => 'IT', 'role' => 'requester'],
            // ลาออกไปแล้ว: บัญชีถูกปิด แต่ประวัติงานที่เคยแจ้งยังอยู่ — ให้ผู้ซื้อเห็นว่าปิดบัญชีแล้วข้อมูลไม่หาย
            // และเป็นคู่กับความสามารถ "แอดมินปิดงานแทนผู้แจ้งที่ลาออก" ซึ่งไม่มีทางลองได้ถ้าทุกบัญชียัง active
            ['username' => 'user_left', 'full_name' => 'กมล (ลาออกแล้ว)', 'dept' => 'OFFICE', 'role' => 'requester', 'active' => false],
        ];

        $ids = ['technician' => [], 'manager' => [], 'requester' => []];
        $created = 0;
        foreach ($staff as $person) {
            try {
                $ids[$person['role']][] = $this->admin->createUser([
                    'username' => $person['username'],
                    'email' => $person['username'] . '@example.com',
                    'password_hash' => password_hash($plainPassword, PASSWORD_BCRYPT),
                    'full_name' => $person['full_name'],
                    'phone' => '',
                    'role' => $person['role'],
                    'department_id' => $departmentIds[$person['dept']] ?? null,
                    'is_active' => (bool) ($person['active'] ?? true),
                ]);
                $created++;
            } catch (DomainException) {
                // Username/email มีอยู่แล้ว — best-effort: ข้าม (ไม่แตะรหัสเดิม)
            }
        }

        return [$ids, $created, $plainPassword];
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
            ['code' => 'PC-001', 'name' => 'คอมพิวเตอร์ฝ่ายบุคคล 01', 'category' => 'COMPUTER', 'location' => 'OFFICE-1F', 'ageMonths' => 48, 'warrantyMonths' => 12],
            ['code' => 'PC-002', 'name' => 'คอมพิวเตอร์ฝ่ายบัญชี 01', 'category' => 'COMPUTER', 'location' => 'OFFICE-2F', 'ageMonths' => 40, 'warrantyMonths' => 12],
            ['code' => 'PRT-001', 'name' => 'เครื่องพิมพ์สำนักงาน (HP LaserJet Pro M404)', 'category' => 'PRINTER', 'location' => 'OFFICE-1F', 'ageMonths' => 60, 'warrantyMonths' => 12],
            ['code' => 'AC-001', 'name' => 'แอร์ห้องประชุม (Daikin Inverter 18000 BTU)', 'category' => 'AC', 'location' => 'MEETING', 'ageMonths' => 66, 'warrantyMonths' => 24],
            ['code' => 'AC-002', 'name' => 'แอร์ห้องทำงานชั้น 2 (Mitsubishi 12000 BTU)', 'category' => 'AC', 'location' => 'OFFICE-2F', 'ageMonths' => 22, 'warrantyMonths' => 24],
            ['code' => 'SRV-001', 'name' => 'เซิร์ฟเวอร์หลัก (Dell PowerEdge R350)', 'category' => 'COMPUTER', 'location' => 'SERVER', 'ageMonths' => 78, 'warrantyMonths' => 36],
            ['code' => 'LGT-001', 'name' => 'หลอด LED ห้องประชุม', 'category' => 'LIGHTING', 'location' => 'MEETING', 'ageMonths' => 6, 'warrantyMonths' => 12],
            ['code' => 'PRT-002', 'name' => 'เครื่องพิมพ์ชั้น 2 (Brother MFC-L2750DW)', 'category' => 'PRINTER', 'location' => 'OFFICE-2F', 'ageMonths' => 12, 'warrantyMonths' => 24],
            // ปลดระวางแล้ว — สติกเกอร์ QR ยังติดอยู่บนตัวเครื่องในชีวิตจริง ผู้ซื้อจึงควรได้ลองสแกนดูว่าระบบตอบว่า
            // "ไม่เปิดรับแจ้งซ่อม" แทนที่จะเป็นหน้า error ซึ่งเป็นพฤติกรรมที่ทีมตั้งใจทำไว้แต่มองไม่เห็นถ้าไม่มีของจริง
            ['code' => 'PC-OLD-01', 'name' => 'PC เก่าฝ่ายบัญชี (ปลดระวาง)', 'category' => 'COMPUTER', 'location' => 'OFFICE-2F', 'ageMonths' => 96, 'warrantyMonths' => 12, 'status' => 'retired'],
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
                    'status' => (string) ($spec['status'] ?? 'active'),
                    'notes' => '',
                    'generated_by' => $createdByUserId > 0 ? $createdByUserId : null,
                ]);
                $assetIds[$spec['code']] = $assetId;
                $count++;
            } catch (DomainException) {
                // asset_code/serial ซ้ำ (หรือ QR-token หมด) — คาดไว้อยู่แล้ว ข้ามได้.
                // ส่วน RuntimeException/PDOException คือพังจริง ปล่อยให้โยนต่อไป ให้ transaction ของ load()
                // rollback การ seed ทั้งหมด ผู้เรียกจะได้เห็น error ไม่ใช่ได้ seed ที่สำเร็จแค่บางส่วนแบบเงียบ ๆ.
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
    private function seedTickets(int $createdByUserId, array $staffIds, array $departmentIds, array $locationIds, array $assetIds, array $ticketCategoryIds, array $priorityIds): int
    {
        $techIds = $staffIds['technician'] ?? [];

        // ต้องมีผู้สร้าง (admin) และช่าง demo อย่างน้อย 1 คน
        if ($createdByUserId <= 0 || $techIds === []) {
            return 0;
        }

        // ผู้แจ้งหมุนเวียนตามบัญชีผู้แจ้งที่ demo สร้างไว้ ไม่ใช่ใส่ admin ทุกใบ: ทุกหน้าจอที่มีคอลัมน์ "ผู้แจ้ง"
        // (คิวงาน รายงานรวม CSAT) เคยขึ้นชื่อ "ผู้ดูแลระบบ" ซ้ำกันทั้งตาราง ซึ่งดูเหมือนข้อมูลเสียมากกว่าตัวอย่าง.
        // ถ้าโหลดซ้ำแล้วบัญชีผู้แจ้งมีอยู่เดิม (createUser โยน DomainException → ไม่ได้ id) จะ fallback เป็น admin
        // เพื่อให้ยังสร้าง ticket ได้ ไม่ใช่ล้มทั้งชุด
        $requesterPool = $staffIds['requester'] ?? [];
        $requesterPool = $requesterPool === [] ? [$createdByUserId] : $requesterPool;
        $managerId = ($staffIds['manager'] ?? [])[0] ?? $createdByUserId;

        // แผนกผู้แจ้ง หมุนเวียนให้มิติ "แผนก" (hotspot/csat/backlog/sla/reopen) มีหลายแถว ไม่ใช่ 'ไม่ระบุแผนก' ล้วน.
        $deptCodes = ['IT', 'FACILITY', 'ADMIN'];

        // spec ต่อ ticket (กระจายให้ทุกรายงานดูเต็ม): [title, daysAgo, status, cat, loc, pri, asset, techIdx,
        //   labor(min), rating[score,fb]|null, resolveHours|null (null=ยังไม่ปิด), reopen]. breach = resolveHours > 8.
        $specs = [
            // ── ปิดงานแล้ว (resolved/completed) ── มีคะแนน/แรงงาน/SLA/บางตัว breach+reopen
            // ไม่ใช้สถานะ closed ในข้อมูลตัวอย่าง: ไม่มี flow ไหนพาไปถึงได้ ผู้ซื้อ template จึงจะเห็นสถานะที่ผู้ใช้
            // ของตัวเองสร้างไม่ได้ (ตัว enum ยังเก็บไว้ตามที่ตัดสินใจไว้ เผื่อทำฟีเจอร์ปิดถาวรในอนาคต)
            ['เครื่องพิมพ์ HR ไม่พิมพ์งาน', 84, 'completed', 'HARDWARE', 'OFFICE-1F', 'MEDIUM', 'PRT-001', 0, 45, [5, 'ช่างมาไว แก้จบในครั้งเดียว ประทับใจมาก'], 3, false],
            ['จอคอมการเงินกระพริบถี่', 78, 'completed', 'HARDWARE', 'OFFICE-2F', 'HIGH', 'PC-001', 1, 90, [4, 'โดยรวมดี แต่รอชิ้นส่วนนานไปหน่อย'], 20, false],
            ['แอร์ห้องประชุมใหญ่ไม่เย็น', 70, 'completed', 'ELECTRICAL', 'MEETING', 'HIGH', 'AC-001', 2, 180, [3, 'เย็นแล้วแต่ใช้เวลานาน'], 30, false],
            ['ไฟห้องเก็บของดับทั้งห้อง', 63, 'resolved', 'ELECTRICAL', 'WAREHOUSE', 'LOW', 'LGT-001', 0, 25, [5, 'รวดเร็วมากครับ'], 2, false],
            ['เมาส์และคีย์บอร์ดฝ่าย IT พัง', 58, 'completed', 'HARDWARE', 'OFFICE-1F', 'LOW', 'PC-002', 1, 15, [4, 'เรียบร้อยดี'], 1, false],
            ['เซิร์ฟเวอร์ช้าผิดปกติทั้งวัน', 52, 'completed', 'SOFTWARE', 'SERVER', 'URGENT', 'SRV-001', 2, 240, [2, 'แก้ช้ามาก งานสะดุดทั้งวัน ต้องตามหลายรอบ'], 48, true],
            ['เครื่องพิมพ์ชั้น 2 กระดาษติดบ่อย', 45, 'completed', 'HARDWARE', 'OFFICE-2F', 'MEDIUM', 'PRT-002', 0, 60, [1, 'เปิดซ้ำหลายรอบยังไม่หายขาด ผิดหวัง'], 36, true],
            ['ติดตั้งโปรแกรมบัญชีเวอร์ชันใหม่', 38, 'completed', 'SOFTWARE', 'OFFICE-1F', 'MEDIUM', 'PC-001', 1, 120, [5, 'ติดตั้งเรียบร้อย สอนใช้งานด้วย ดีมาก'], 5, false],
            ['แอร์โรงอาหารมีเสียงดัง', 30, 'resolved', 'ELECTRICAL', 'MEETING', 'MEDIUM', 'AC-002', 2, 75, null, 6, false],
            ['สายแลนหลุดห้องประชุมย่อย', 2, 'completed', 'HARDWARE', 'MEETING', 'LOW', 'PC-002', 0, 20, [4, 'ok ครับ'], 2, false],
            ['จอมอนิเตอร์ห้องเซิร์ฟเวอร์ไม่ติด', 4, 'completed', 'HARDWARE', 'SERVER', 'HIGH', 'SRV-001', 1, 50, [3, 'พอใช้ได้'], 4, false],
            ['ไฟออฟฟิศชั้น 1 บางดวงดับ', 6, 'resolved', 'ELECTRICAL', 'OFFICE-1F', 'LOW', 'LGT-001', 2, 30, null, 3, false],
            // ── งานค้าง (backlog) ── ยังไม่ปิด อายุหลากหลาย (2 ตัวเกิน 30 วัน)
            ['PC ฝ่ายขายเปิดไม่ติด (รอชิ้นส่วน)', 40, 'in_progress', 'HARDWARE', 'OFFICE-2F', 'HIGH', 'PC-002', 0, 30, null, null, false],
            ['แอร์ห้องเซิร์ฟเวอร์ไม่เย็นพอ', 33, 'in_progress', 'ELECTRICAL', 'SERVER', 'URGENT', 'AC-001', 1, 60, null, null, false],
            ['ตั้งค่าเครื่องพิมพ์ใหม่ยังไม่ได้', 15, 'in_progress', 'HARDWARE', 'OFFICE-1F', 'MEDIUM', 'PRT-002', 2, 20, null, null, false],
            ['ขอเพิ่ม RAM เครื่องกราฟิก', 9, 'accepted', 'HARDWARE', 'OFFICE-2F', 'LOW', 'PC-001', 0, 0, null, null, false],
            ['ไฟคลังสินค้ากระพริบ', 5, 'assigned', 'ELECTRICAL', 'WAREHOUSE', 'MEDIUM', 'LGT-001', 1, 0, null, null, false],
            ['เมนบอร์ดเซิร์ฟเวอร์สำรองเสีย', 3, 'in_progress', 'HARDWARE', 'SERVER', 'HIGH', 'SRV-001', 2, 0, null, null, false],
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
            $isRejected = $status === 'rejected';
            $isCancelled = $status === 'cancelled';
            $isTerminalReject = $isRejected || $isCancelled;
            $hasAssignment = in_array($status, ['assigned', 'accepted', 'in_progress', 'resolved', 'completed', 'closed'], true);
            $hasResponse = in_array($status, ['accepted', 'in_progress', 'resolved', 'completed', 'closed'], true);
            $hasStarted = in_array($status, ['in_progress', 'resolved', 'completed', 'closed'], true);
            $approvalStatus = $status === 'rejected' ? 'rejected' : 'approved';
            $tech = $techIds[$techIdx % $techCount] ?? null;
            $deptId = $departmentIds[$deptCodes[$index % count($deptCodes)]] ?? null;

            $approvedAt = $isRejected ? null : date('Y-m-d H:i:s', $reqTs + 30 * 60);
            $initialAssignedAt = $hasAssignment ? date('Y-m-d H:i:s', $reqTs + 40 * 60) : null;
            $initialResponseAt = $hasResponse ? date('Y-m-d H:i:s', $reqTs + 45 * 60) : null;
            $initialStartedAt = $hasStarted ? date('Y-m-d H:i:s', $reqTs + 50 * 60) : null;
            $resolvedAt = $isDone ? date('Y-m-d H:i:s', $reqTs + (int) $resolveHours * 3600) : null;
            $firstResolvedAt = $reopen ? date('Y-m-d H:i:s', $reqTs + 4 * 3600) : $resolvedAt;
            $reopenedAt = $reopen ? date('Y-m-d H:i:s', $reqTs + 12 * 3600) : null;

            // การเปิดงานซ้ำเริ่มรอบรายงานใหม่. ฟิลด์ปัจจุบันของ ticket/work-order สะท้อนรอบล่าสุด
            // ส่วนรอบแรกถูกแช่แข็งไว้ในประวัติ SLA/activity.
            $assignedAt = $reopen ? $reopenedAt : $initialAssignedAt;
            $firstResponseAt = $reopen ? date('Y-m-d H:i:s', $reqTs + 12 * 3600 + 5 * 60) : $initialResponseAt;
            $startedAt = $reopen ? date('Y-m-d H:i:s', $reqTs + 12 * 3600 + 10 * 60) : $initialStartedAt;
            $completedAt = in_array($status, ['completed', 'closed'], true) && $resolvedAt !== null
                ? date('Y-m-d H:i:s', (strtotime($resolvedAt) ?: $reqTs) + 15 * 60)
                : null;
            $closedAt = $status === 'closed' && $completedAt !== null
                ? date('Y-m-d H:i:s', (strtotime($completedAt) ?: $reqTs) + 15 * 60)
                : null;
            $cancelledAt = $isCancelled ? date('Y-m-d H:i:s', $reqTs + 60 * 60) : null;
            $initialResponseDue = date('Y-m-d H:i:s', $reqTs + 3600);
            $initialResolutionDue = date('Y-m-d H:i:s', $reqTs + 8 * 3600);
            $responseDue = $reopen
                ? date('Y-m-d H:i:s', $reqTs + 13 * 3600)
                : $initialResponseDue;
            $resolutionDue = $reopen
                ? date('Y-m-d H:i:s', $reqTs + 20 * 3600)
                : $initialResolutionDue;

            $ticketId = $this->tickets->createSeedTicket([
                'ticket_no' => sprintf('DEMO-%03d', $index),
                'title' => $title,
                // ใบแรกได้รายละเอียดยาวแบบที่คนแจ้งจริงเขียน (หลายบรรทัด อาการ + สิ่งที่ลองแล้ว + ผลกระทบ) เพื่อให้
                // ผู้ซื้อเห็นว่าข้อความไทยยาว ๆ ไม่ถูกตัดทิ้งและขึ้นบรรทัดถูกต้อง — ใบที่เหลือใช้บรรทัดเดียวพอ
                'description' => $index === 1
                    ? $title . "\n\nอาการ: เป็นมาตั้งแต่เมื่อวานช่วงบ่าย ตอนแรกเป็น ๆ หาย ๆ พอเช้านี้เป็นตลอดทั้งวัน\n"
                        . "สิ่งที่ลองแล้ว: ปิดเปิดเครื่องใหม่สองรอบ สลับปลั๊กไปอีกช่องหนึ่ง และลองเปลี่ยนสายแล้ว อาการเหมือนเดิม\n"
                        . "ผลกระทบ: ทั้งแผนกใช้เครื่องนี้ร่วมกัน ตอนนี้ต้องเดินไปใช้ของอีกชั้นหนึ่งแทน เสียเวลามาก\n"
                        . 'รบกวนช่วยดูให้หน่อยนะครับ ถ้าต้องสั่งอะไหล่รบกวนแจ้งกลับด้วยว่าใช้เวลากี่วัน ขอบคุณครับ'
                    : $title . ' — รายละเอียดตัวอย่างสำหรับสาธิตระบบ',
                'requester_id' => $requesterPool[$index % count($requesterPool)],
                'requester_department_id' => $deptId,
                'location_id' => $locationIds[$loc] ?? 0,
                'asset_id' => $assetIds[$asset] ?? null,
                'ticket_category_id' => $ticketCategoryIds[$cat] ?? 0,
                'priority_id' => $priorityIds[$pri] ?? 0,
                'manager_id' => $managerId,
                'technician_id' => $isTerminalReject ? null : $tech,
                'approval_status' => $approvalStatus,
                'status' => $status,
                'requested_at' => $requestedAt,
                'response_due_at' => $responseDue,
                'resolution_due_at' => $resolutionDue,
                'approved_at' => $approvedAt,
                'assigned_at' => $assignedAt,
                'first_response_at' => $firstResponseAt,
                'started_at' => $startedAt,
                'resolved_at' => $resolvedAt,
                'completed_at' => $completedAt,
                'cancelled_at' => $cancelledAt,
                'closed_at' => $closedAt,
            ]);

            if ($ticketId <= 0) {
                continue;
            }
            $count++;

            $approvalAction = $isRejected ? 'rejected' : 'approved';
            $approvalActedAt = $isRejected
                ? date('Y-m-d H:i:s', $reqTs + 30 * 60)
                : $approvedAt;
            $this->tickets->createSeedApproval($ticketId, $createdByUserId, $approvalAction, $approvalActedAt);
            $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_submitted', null, 'pending_approval', $requestedAt);

            if ($isRejected) {
                $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_rejected', 'pending_approval', 'rejected', (string) $approvalActedAt);
                continue;
            }

            $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_approved', 'pending_approval', 'approved', (string) $approvedAt);
            if ($isCancelled) {
                $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_cancelled', 'approved', 'cancelled', (string) $cancelledAt);
                continue; // terminal ที่ไม่เกิดงานจริงจึงไม่มี work order, SLA หรือคะแนน
            }

            if ($tech !== null && $assignedAt !== null) {
                $woStatus = match ($status) {
                    'assigned' => 'assigned',
                    'accepted' => 'accepted',
                    'in_progress' => 'in_progress',
                    default => 'completed',
                };
                $this->tickets->createSeedWorkOrder(
                    $ticketId,
                    $tech,
                    $createdByUserId,
                    $woStatus,
                    $labor,
                    $assignedAt,
                    $isDone ? $resolvedAt : null,
                    $firstResponseAt,
                    $startedAt,
                );
            }

            // รอบแรกของวงจรชีวิตงาน
            $this->seedSlaCycle($ticketId, 'response', $initialResponseDue, $initialResponseAt, 1);
            $this->seedSlaCycle($ticketId, 'resolution', $initialResolutionDue, $firstResolvedAt, 1);
            $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'technician_assigned', 'approved', 'assigned', (string) $initialAssignedAt);
            if ($initialResponseAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'work_accepted', 'assigned', 'accepted', $initialResponseAt);
            }
            if ($initialStartedAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'work_started', 'accepted', 'in_progress', $initialStartedAt);
            }

            if ($reopen && $firstResolvedAt !== null && $reopenedAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'ticket_resolved', 'in_progress', 'resolved', $firstResolvedAt);
                $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_reopened', 'resolved', 'assigned', $reopenedAt);
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'work_accepted', 'assigned', 'accepted', (string) $firstResponseAt);
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'work_started', 'accepted', 'in_progress', (string) $startedAt);
                $this->seedSlaCycle($ticketId, 'response', $responseDue, $firstResponseAt, 2);
                $this->seedSlaCycle($ticketId, 'resolution', $resolutionDue, $resolvedAt, 2);
            }

            if ($isDone && $resolvedAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $tech ?? $createdByUserId, 'ticket_resolved', 'in_progress', 'resolved', $resolvedAt);
            }

            if ($completedAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_completed', 'resolved', 'completed', $completedAt);
            }
            if ($closedAt !== null) {
                $this->tickets->createSeedActivityLog($ticketId, $createdByUserId, 'ticket_closed', 'completed', 'closed', $closedAt);
            }

            // คะแนนเกิดหลังผู้แจ้งยืนยัน completed เท่านั้น; resolved ยังรอการยืนยัน จึงยังให้คะแนนไม่ได้.
            if ($rating !== null && $completedAt !== null) {
                $this->tickets->createSeedRating(
                    $ticketId,
                    $createdByUserId,
                    $tech,
                    (int) $rating[0],
                    (string) $rating[1],
                    $reopen ? 2 : 1,
                    $completedAt,
                );
            }
        }

        return $count;
    }

    /**
     * สรุปผล SLA ของ demo ให้เหมือน operation จริง: ถ้าทำสำเร็จช้า achieved_at และ breached_at
     * จะเป็นเวลาเดียวกัน; ถ้ายังไม่ทำและเลยกำหนดแล้วจะมีเฉพาะ breached_at.
     */
    private function seedSlaCycle(int $ticketId, string $metric, string $targetAt, ?string $achievedAt, int $cycle): void
    {
        $breached = $achievedAt === null || $achievedAt > $targetAt;
        $breachedAt = $breached
            ? ($achievedAt ?? date('Y-m-d H:i:s', (strtotime($targetAt) ?: time()) + 1))
            : null;

        $this->tickets->createSeedSlaTrack(
            $ticketId,
            $metric,
            $targetAt,
            $achievedAt,
            $breachedAt,
            $breached ? 'breached' : 'met',
            $cycle,
        );
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
                // มีอยู่แล้ว — ข้ามแบบเงียบ ๆ รันซ้ำกี่ครั้งผลก็เหมือนเดิม (idempotent)
            }
        }
        return $count;
    }

    /**
     * สร้าง map [code => id] จากผลลัพธ์ "getX" ของ Repository ที่คืน row ที่มี 'id' + 'code'.
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
