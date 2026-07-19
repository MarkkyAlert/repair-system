<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommentRepository;
use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use DomainException;
use PDO;
use Throwable;

class TicketService
{
    public function __construct(
        private CommentRepository $comments,
        private TicketRepository $tickets,
        private NotificationService $notifications,
        private AttachmentService $attachments,
        private PDO $db,
        private TicketPolicy $policy,
        private TicketReadRepository $reads,
    ) {
    }

    /**
     * รวบรวม view-model ของหน้า dashboard: metric สรุป, ticket ล่าสุด, กราฟรายเดือน/breakdown/CSAT, top list,
     * แจ้งเตือนงานด่วน และ (เฉพาะ admin) สถานะ cron + checklist ตั้งค่า. อ่านอย่างเดียว — ไม่เขียน DB.
     * breakdown/CSAT/top-list ถูกจำกัดขอบเขตตามปี ($filters['year']) เมื่อผู้ใช้ไม่ได้ตั้งช่วงวันที่เอง; metric/recent เป็น all-time.
     * @param array<string, mixed> $viewer ต้องมี 'id' และ 'role' (คุมสิทธิ์การมองเห็น + เลือก CTA/พาเนลของ admin)
     * @param array<string, mixed> $filters คีย์ที่รับ: 'from_date'/'to_date' (ช่วงวันที่), 'department_id', 'category_id',
     *   'status', 'year', 'preset' (mine|overdue|pending_approval|today); ค่านอกเหนือถูก normalize ทิ้ง
     * @return array<string, mixed> view-model (metrics/recentTickets/charts/filters/highlights/csat/...)
     */
    public function getDashboardData(array $viewer, array $filters = []): array
    {
        $normalizedFilters = $this->normalizeDashboardFilters($filters);
        $normalizedFilters['preset_user_id'] = (int) ($viewer['id'] ?? 0);
        $normalizedFilters['preset_role'] = (string) ($viewer['role'] ?? 'guest');
        $reference = $this->reads->getDashboardFilterReferenceData();
        $metrics = $this->reads->getDashboardMetrics($viewer, $normalizedFilters);
        $recentTickets = array_map(
            fn (array $ticket): array => $this->mapTicketSummary($ticket),
            $this->reads->getRecentTickets($viewer, $normalizedFilters, 5)
        );
        $year = (int) ($normalizedFilters['year'] ?? (int) date('Y'));

        // Breakdowns/CSAT: จำกัดขอบเขตตามปีที่เลือกเมื่อผู้ใช้ไม่ได้ตั้งช่วงวันที่เอง — ให้ตรงกับกราฟรายเดือน
        // และได้ใช้ index idx_tickets_requested_at แทนการ aggregate ทั้งระบบ. metrics กับ recent ยังเป็น all-time (สถานะปัจจุบัน).
        $breakdownFilters = $normalizedFilters;
        if (($normalizedFilters['from_datetime'] ?? '') === '' && ($normalizedFilters['to_datetime'] ?? '') === '') {
            $breakdownFilters['from_datetime'] = sprintf('%04d-01-01 00:00:00', $year);
            $breakdownFilters['to_datetime'] = sprintf('%04d-12-31 23:59:59', $year);
        }

        $monthlyTickets = $this->reads->getDashboardMonthlyTicketCounts($viewer, $normalizedFilters, $year);
        $categoryBreakdown = $this->reads->getDashboardCategoryBreakdown($viewer, $breakdownFilters, 6);
        $departmentBreakdown = $this->reads->getDashboardDepartmentBreakdown($viewer, $breakdownFilters, 6);
        $resolutionTrend = $this->reads->getDashboardMonthlyResolutionAverages($viewer, $normalizedFilters, $year);
        $topTechnicians = $this->reads->getDashboardTopTechnicians($viewer, $breakdownFilters, 5);
        $topCategories = $this->reads->getDashboardTopCategories($viewer, $breakdownFilters, 5);
        $csat = $this->reads->getCsatSummary($viewer, $breakdownFilters);

        $formattedMetrics = $this->formatMetrics($metrics);
        $charts = [
            'monthlyTickets' => $this->buildDashboardChart(
                'Tickets / Month',
                $this->monthLabels(),
                $this->buildMonthlySeries($monthlyTickets, 'total_tickets')
            ),
            'categoryBreakdown' => $this->buildDashboardChart(
                'Tickets by Category',
                array_map(fn (array $row): string => (string) ($row['category_name'] ?? '-'), $categoryBreakdown),
                array_map(fn (array $row): int => (int) ($row['total_tickets'] ?? 0), $categoryBreakdown)
            ),
            'departmentBreakdown' => $this->buildDashboardChart(
                'Tickets by Department',
                array_map(fn (array $row): string => (string) ($row['department_name'] ?? '-'), $departmentBreakdown),
                array_map(fn (array $row): int => (int) ($row['total_tickets'] ?? 0), $departmentBreakdown)
            ),
            'resolutionTrend' => $this->buildDashboardChart(
                'Avg Resolution Hours',
                $this->monthLabels(),
                $this->buildMonthlySeries($resolutionTrend, 'avg_minutes', true)
            ),
        ];

        return [
            'metrics' => $formattedMetrics,
            'recentTickets' => $recentTickets,
            'filters' => $this->buildDashboardFilterData($normalizedFilters, $reference),
            'charts' => $charts,
            'primaryCta' => $this->buildDashboardPrimaryCta((string) ($viewer['role'] ?? 'guest')),
            'cronHealth' => $this->buildDashboardCronHealth((string) ($viewer['role'] ?? 'guest')),
            'cronFailures' => $this->buildDashboardCronFailures((string) ($viewer['role'] ?? 'guest')),
            'setupChecklist' => $this->buildAdminSetupChecklist((string) ($viewer['role'] ?? 'guest')),
            'urgentAlerts' => $this->buildDashboardUrgentAlerts($formattedMetrics),
            'chartSummaries' => $this->buildDashboardChartSummaries($charts),
            'highlights' => [
                'topTechnicians' => array_map(fn (array $row): array => [
                    'name' => (string) ($row['full_name'] ?? '-'),
                    'ticket_count' => (int) ($row['ticket_count'] ?? 0),
                    'avg_rating' => (float) ($row['avg_rating'] ?? 0),
                    'avg_rating_label' => (float) ($row['avg_rating'] ?? 0) > 0 ? number_format((float) ($row['avg_rating'] ?? 0), 1) : '-',
                    'overdue_count' => (int) ($row['overdue_count'] ?? 0),
                ], $topTechnicians),
                'topCategories' => array_map(fn (array $row): array => [
                    'name' => (string) ($row['category_name'] ?? '-'),
                    'ticket_count' => (int) ($row['total_tickets'] ?? 0),
                    'overdue_count' => (int) ($row['overdue_count'] ?? 0),
                ], $topCategories),
            ],
            'csat' => [
                'total_ratings' => (int) ($csat['total_ratings'] ?? 0),
                'average_score' => (float) ($csat['average_score'] ?? 0),
                'average_label' => number_format((float) ($csat['average_score'] ?? 0), 1),
                'positive_percent' => (int) ($csat['positive_percent'] ?? 0),
                'distribution' => $csat['distribution'] ?? [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            ],
        ];
    }

    /** ปุ่ม CTA หลักตาม role สำหรับหัว dashboard (view-model = ข้อมูลที่จัดรูปไว้ให้หน้าจอแสดง). */
    private function buildDashboardPrimaryCta(string $role): array
    {
        return match ($role) {
            'manager' => ['label' => 'ตรวจงานรออนุมัติ', 'href' => '/dashboard?preset=pending_approval', 'icon' => 'clipboard-list'],
            'technician' => ['label' => 'ดูงานของฉัน', 'href' => '/tickets', 'icon' => 'wrench'],
            default => ['label' => 'แจ้งปัญหาใหม่', 'href' => '/tickets/create', 'icon' => 'arrow-right'],
        };
    }

    /** เฉพาะ admin: cron ที่ยังไม่รันภายในช่วงเวลาที่ควรจะยังสด — view-model. */
    private function buildDashboardCronHealth(string $role): array
    {
        if ($role !== 'admin') {
            return [];
        }

        $checks = [
            ['key' => 'cron_email_queue_last_run_at', 'label' => 'คิวอีเมล', 'staleMinutes' => 30, 'href' => '/admin/email-queue'],
            ['key' => 'cron_overdue_check_last_run_at', 'label' => 'ตรวจ SLA เกินกำหนด', 'staleMinutes' => 60, 'href' => '/admin/email-queue'],
            ['key' => 'cron_backup_last_run_at', 'label' => 'สำรอง database', 'staleMinutes' => BackupService::STALE_MINUTES, 'href' => '/admin#tab-backup'],
            ['key' => 'cron_orphan_cleanup_last_run_at', 'label' => 'ล้างไฟล์แนบกำพร้า', 'staleMinutes' => 60 * 24 * 8, 'href' => '/admin/email-queue'],
        ];

        $stale = [];
        foreach ($checks as $check) {
            $lastRun = (string) setting($check['key'], '');
            $lastTs = $lastRun !== '' ? strtotime($lastRun) : false;
            if ($lastTs === false || $lastTs < (time() - ($check['staleMinutes'] * 60))) {
                $stale[] = ['label' => $check['label'], 'last_run' => $lastRun, 'href' => $check['href']];
            }
        }

        return $stale;
    }

    /**
     * คำเตือนเรื่อง "ผลลัพธ์" ของ cron — คนละเรื่องกับการที่มันค้างไม่รัน: รอบล่าสุดรันจบแล้ว แต่ยังทิ้ง
     * ความล้มเหลวขั้นสุดท้ายไว้ เช่น อีเมลที่ลองส่งครบจำนวนครั้งแต่ยังไม่สำเร็จ หรือ SLA เกินกำหนดที่แจ้งเตือนไม่เคยถูกส่ง.
     * แค่ heartbeat ที่ดูปกติอย่างเดียวจะบังปัญหาพวกนี้ไว้ dashboard เลยต้องดึงมาโชว์.
     *
     * @return array<int, array{label: string, detail: string, href: string}>
     */
    private function buildDashboardCronFailures(string $role): array
    {
        if ($role !== 'admin') {
            return [];
        }

        // อ่านค่าสดผ่าน repository ตรง ๆ ไม่ผ่าน helper setting() ที่ cache ไว้ต่อ request — ความล้มเหลวของ cron ที่เพิ่งถูกบันทึก
        // ต้องโชว์ทันที และยังทำให้เทสต์ตรวจสัญญาณนี้ได้ด้วย.
        $settings = app(\App\Repositories\SettingsRepository::class);
        $count = static function (string $key) use ($settings): int {
            if (!$settings instanceof \App\Repositories\SettingsRepository) {
                return 0;
            }
            $row = $settings->getByKey($key);

            return is_array($row) ? (int) ($row['setting_value'] ?? 0) : 0;
        };

        $failures = [];
        $emailFailed = $count('cron_email_queue_last_failed');
        if ($emailFailed > 0) {
            $failures[] = ['label' => 'คิวอีเมล', 'detail' => $emailFailed . ' ฉบับส่งไม่สำเร็จ (ครบจำนวนครั้งที่ลองแล้ว)', 'href' => '/admin/email-queue'];
        }
        $slaNotifyFailed = $count('cron_sla_notify_last_failed');
        if ($slaNotifyFailed > 0) {
            $failures[] = ['label' => 'แจ้งเตือน SLA เกินกำหนด', 'detail' => $slaNotifyFailed . ' รายการแจ้งเตือนไม่สำเร็จ', 'href' => '/admin/email-queue'];
        }
        $backupFailed = $count('cron_backup_last_failed');
        if ($backupFailed > 0) {
            $failures[] = ['label' => 'สำรอง database', 'detail' => $backupFailed . ' ไฟล์เก่าลบไม่สำเร็จ (พื้นที่อาจเต็ม)', 'href' => '/admin#tab-backup'];
        }
        // รอบล้างไฟล์ที่รันจบแล้ว แต่ลบไฟล์กำพร้า (orphan = ไฟล์ที่ไม่มีใครอ้างถึง) บางไฟล์ไม่ได้ เพราะสิทธิ์หรือดิสก์เต็ม —
        // ถ้าดูแค่ heartbeat เรื่องความสดอย่างเดียวจะเห็นว่ามันปกติดี.
        $orphanFailed = $count('cron_orphan_cleanup_last_failed');
        if ($orphanFailed > 0) {
            $failures[] = ['label' => 'ล้างไฟล์แนบกำพร้า', 'detail' => $orphanFailed . ' ไฟล์กำพร้าลบไม่สำเร็จ (สิทธิ์/พื้นที่อาจมีปัญหา)', 'href' => '/admin/email-queue'];
        }

        return $failures;
    }

    /**
     * Admin-only: checklist แนะนำตั้งค่าหลัง setup (5 ข้อ + สถานะ done + ลิงก์ไปตั้งค่า). ว่างถ้าไม่ใช่ admin.
     * @return array{items: array<int, array>, done_count: int, total: int, complete: bool}|array{}
     */
    private function buildAdminSetupChecklist(string $role): array
    {
        if ($role !== 'admin') {
            return [];
        }

        $items = [
            [
                'key' => 'mail', 'icon' => 'send', 'label' => 'ตั้งค่าอีเมล (SMTP)',
                'hint' => 'เพื่อส่งอีเมลแจ้งเตือนและรีเซ็ตรหัสผ่าน',
                'done' => strtolower((string) config('mail.driver', 'log')) !== 'log',
                'href' => '/admin#tab-email', 'cta' => 'ตั้งค่าอีเมล',
            ],
            [
                'key' => 'logo', 'icon' => 'settings', 'label' => 'อัปโหลดโลโก้องค์กร',
                'hint' => 'แสดงบนหัวระบบ หน้าเข้าสู่ระบบ และรายงาน PDF',
                'done' => branding_logo_url() !== null,
                'href' => '/admin#tab-settings', 'cta' => 'ใส่โลโก้',
            ],
            [
                'key' => 'users', 'icon' => 'users', 'label' => 'เพิ่มผู้ใช้และช่างเทคนิค',
                'hint' => 'สร้างบัญชีช่างเพื่อรับและปิดงาน',
                'done' => $this->reads->countTechnicians() >= 1,
                'href' => '/admin#tab-users', 'cta' => 'เพิ่มผู้ใช้',
            ],
            [
                'key' => 'data', 'icon' => 'clipboard-list', 'label' => 'มีข้อมูลในระบบ',
                'hint' => 'โหลดข้อมูลตัวอย่างเพื่อทดลอง หรือเริ่มแจ้งซ่อมจริง',
                'done' => $this->reads->countAllTickets() > 0,
                'href' => '/admin#tab-settings', 'cta' => 'โหลดตัวอย่าง',
            ],
            [
                'key' => 'cron', 'icon' => 'refresh-cw', 'label' => 'ตั้ง cron ให้ทำงานอัตโนมัติ',
                'hint' => 'ส่งคิวอีเมล · ตรวจ SLA เกินกำหนด · สำรองข้อมูล',
                'done' => $this->buildDashboardCronHealth('admin') === [],
                'href' => '/dashboard', 'cta' => 'ดูสถานะ cron',
            ],
        ];

        $doneCount = count(array_filter($items, static fn (array $item): bool => (bool) $item['done']));

        return [
            'items' => $items,
            'done_count' => $doneCount,
            'total' => count($items),
            'complete' => $doneCount === count($items),
        ];
    }

    /** การแจ้งเตือนงานด่วนที่แสดงเหนือ dashboard (view-model). */
    private function buildDashboardUrgentAlerts(array $metrics): array
    {
        $alerts = [];
        $overdue = max(0, (int) ($metrics['overdue'] ?? 0));
        $pendingApproval = max(0, (int) ($metrics['pendingApproval'] ?? 0));
        if ($overdue > 0) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'triangle-alert', 'label' => 'มีงานเกิน SLA ' . $overdue . ' รายการ', 'href' => '/tickets'];
        }
        if ($pendingApproval > 0) {
            $alerts[] = ['tone' => 'warning', 'icon' => 'clock', 'label' => 'มีงานรออนุมัติ ' . $pendingApproval . ' รายการ', 'href' => '/tickets?status=pending_approval'];
        }

        return $alerts;
    }

    /** บรรทัดสรุป total/top/avg ของแต่ละกราฟ (view-model) สำหรับกราฟทั้งหมดบน dashboard. */
    private function buildDashboardChartSummaries(array $charts): array
    {
        return [
            'monthlyTickets' => $this->summarizeChart($charts['monthlyTickets'] ?? [], 'รายการ'),
            'categoryBreakdown' => $this->summarizeChart($charts['categoryBreakdown'] ?? [], 'รายการ'),
            'departmentBreakdown' => $this->summarizeChart($charts['departmentBreakdown'] ?? [], 'รายการ'),
            'resolutionTrend' => $this->summarizeChart($charts['resolutionTrend'] ?? [], 'ชั่วโมง'),
        ];
    }

    private function summarizeChart(array $chart, string $unit): array
    {
        $labels = is_array($chart['labels'] ?? null) ? array_values($chart['labels']) : [];
        $data = is_array($chart['data'] ?? null) ? array_values($chart['data']) : [];
        $values = array_map(static fn ($value): float => (float) $value, $data);
        $total = array_sum($values);
        $topValue = $values === [] ? 0.0 : max($values);
        $topIndex = $values === [] ? false : array_search($topValue, $values, true);
        $topLabel = $topIndex !== false && isset($labels[$topIndex]) ? (string) $labels[$topIndex] : '-';
        $nonZero = array_filter($values, static fn (float $v): bool => $v > 0);
        $avg = $nonZero === [] ? 0.0 : array_sum($nonZero) / count($nonZero);
        $fmt = static fn (float $v): string => rtrim(rtrim(number_format($v, 1), '0'), '.');

        return [
            'total' => $fmt($total) . ' ' . $unit,
            'top' => $topLabel . ' ' . $fmt($topValue) . ' ' . $unit,
            'avg' => $fmt($avg) . ' ' . $unit,
        ];
    }

    /**
     * view-model ของหน้าคิว ticket: metric สรุป 5 ตัว + รายการ ticket ที่ผู้ดูเห็น (แบ่งหน้า 20/หน้า) + ตัวช่วยฟิลเตอร์.
     * อ่านอย่างเดียว. ตั้งใจไม่เรียก dashboard เต็ม (กราฟ/breakdown/CSAT) เพื่อเลี่ยง query เพิ่มอีก ~11 ครั้ง.
     * @param array<string, mixed> $viewer ต้องมี 'id'/'role' (คุมสิทธิ์การมองเห็นคิว)
     * @param array<string, mixed> $filters คีย์ที่รับ: 'q' (คำค้น), 'status', 'priority', 'technician_id',
     *   'sla' (มีผลเฉพาะค่า 'overdue'), 'page' (เริ่มที่ 1)
     * @return array<string, mixed> view-model (metrics/tickets/filters/pagination/urgentAlerts/...)
     */
    public function getTicketIndexData(array $viewer, array $filters = []): array
    {
        // ตรงนี้ต้องใช้แค่ metric สรุป 5 ตัวเท่านั้น — ดึงมาตรง ๆ แทนที่จะ
        // รัน dashboard เต็ม (กราฟ, breakdown, CSAT, top list = query เพิ่มอีก ~11 ครั้ง).
        $metrics = $this->formatMetrics($this->reads->getDashboardMetrics($viewer, []));
        $normalized = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'priority' => strtoupper(trim((string) ($filters['priority'] ?? ''))),
            'technician_id' => max(0, (int) ($filters['technician_id'] ?? 0)),
            'sla' => trim((string) ($filters['sla'] ?? '')) === 'overdue' ? 'overdue' : '',
        ];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $result = $this->reads->getVisibleTicketsPage($viewer, $normalized, $page, 20);
        $tickets = array_map(fn (array $ticket): array => $this->mapTicketSummary($ticket), $result['items']);
        $technicians = array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['full_name'],
        ], $this->reads->getActiveTechnicians());

        return [
            'metrics' => $metrics,
            'tickets' => $tickets,
            'roleLabel' => role_label_th((string) ($viewer['role'] ?? 'guest')),
            'filters' => $normalized + ['technicians' => $technicians],
            'pagination' => $result,
            'queueMaxId' => $this->reads->getMaxVisibleTicketId($viewer),
            'activeFilterChips' => $this->buildTicketFilterChips($normalized, $technicians),
            'urgentAlerts' => $this->buildTicketUrgentAlerts($metrics),
        ];
    }

    /**
     * chip ของฟิลเตอร์ที่กำลังใช้อยู่ (view-model): แต่ละฟิลเตอร์มี label กับ URL สำหรับกดปิด.
     * ย้ายออกมาจาก tickets/index.php ให้ template ทำหน้าที่แค่แสดงผลอย่างเดียว.
     */
    private function buildTicketFilterChips(array $filters, array $technicians): array
    {
        $dismissUrl = static function (string $removeKey) use ($filters): string {
            $query = [
                'q' => (string) ($filters['q'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'priority' => (string) ($filters['priority'] ?? ''),
                'technician_id' => (int) ($filters['technician_id'] ?? 0) > 0 ? (string) $filters['technician_id'] : '',
                'sla' => (string) ($filters['sla'] ?? ''),
            ];
            unset($query[$removeKey]);
            $query = array_filter($query, static fn ($v): bool => (string) $v !== '');

            return url('/tickets' . ($query !== [] ? '?' . http_build_query($query) : ''));
        };

        $chips = [];
        if ((string) ($filters['status'] ?? '') !== '') {
            $chips[] = ['label' => 'สถานะ: ' . ticket_status_label_th((string) $filters['status']), 'dismiss' => $dismissUrl('status')];
        }
        if ((string) ($filters['priority'] ?? '') !== '') {
            $chips[] = ['label' => 'ความสำคัญ: ' . priority_label_th((string) $filters['priority']), 'dismiss' => $dismissUrl('priority')];
        }
        if ((int) ($filters['technician_id'] ?? 0) > 0) {
            $techId = (int) $filters['technician_id'];
            $techLabel = (string) $techId;
            foreach ($technicians as $technician) {
                if ((int) ($technician['id'] ?? 0) === $techId) {
                    $techLabel = (string) ($technician['label'] ?? $techLabel);
                    break;
                }
            }
            $chips[] = ['label' => 'ช่าง: ' . $techLabel, 'dismiss' => $dismissUrl('technician_id')];
        }
        if ((string) ($filters['sla'] ?? '') === 'overdue') {
            $chips[] = ['label' => 'SLA: เกินกำหนด', 'dismiss' => $dismissUrl('sla')];
        }

        return $chips;
    }

    /** chip แจ้งเตือนงานด่วน (view-model) ที่แสดงเหนือคิว ticket. */
    private function buildTicketUrgentAlerts(array $metrics): array
    {
        $alerts = [];
        $overdue = max(0, (int) ($metrics['overdue'] ?? 0));
        $pendingApproval = max(0, (int) ($metrics['pendingApproval'] ?? 0));
        if ($overdue > 0) {
            $alerts[] = ['tone' => 'danger', 'icon' => 'triangle-alert', 'label' => 'มีงานเกิน SLA ' . $overdue . ' รายการ', 'href' => '/tickets?sla=overdue'];
        }
        if ($pendingApproval > 0) {
            $alerts[] = ['tone' => 'warning', 'icon' => 'clock', 'label' => 'มีงานรออนุมัติ ' . $pendingApproval . ' รายการ', 'href' => '/tickets?status=pending_approval'];
        }

        return $alerts;
    }

    /**
     * Max visible ticket id สำหรับ live poll หน้าคิว (GET /tickets/state).
     */
    public function getQueueMaxVisibleId(array $viewer): int
    {
        return $this->reads->getMaxVisibleTicketId($viewer);
    }

    /**
     * view-model ของฟอร์มแจ้งซ่อมใหม่: ตัวเลือก priority/category/location/asset + ค่า default ของฟอร์ม.
     * อ่านอย่างเดียว (ไม่เขียน DB) แต่จะ generate submission_token ใหม่ให้ถ้ายังไม่มี (กันกดส่งซ้ำตอน POST).
     * @param array<string, mixed> $viewer ใช้ 'full_name'/'email' เติมช่องผู้แจ้ง
     * @param array<string, mixed> $oldInput ค่าที่ผู้ใช้กรอกไว้ (repopulate หลัง validation fail); ถ้าว่างจึงใช้ $prefill
     * @param array<string, mixed> $prefill ค่าตั้งต้น เช่น 'asset_id', 'source_ticket_id'/'source_ticket_no' และฟิลด์ฟอร์มตอนกด duplicate
     * @return array<string, mixed> view-model ของฟอร์ม (priorities/categories/locations/assets/defaults/...)
     */
    public function getCreateFormData(array $viewer, array $oldInput = [], array $prefill = []): array
    {
        $reference = $this->reads->getCreateFormReferenceData();
        $prefillAsset = null;

        if ($oldInput === []) {
            $prefillAssetId = (int) ($prefill['asset_id'] ?? 0);
            if ($prefillAssetId > 0) {
                $prefillAsset = $this->findReferenceById($reference['assets'] ?? [], $prefillAssetId);
            }
        }

        return [
            'priorities' => array_map(fn (array $priority): array => [
                'id' => (int) $priority['id'],
                'label' => (string) $priority['name'] . ' (' . (string) $priority['code'] . ')',
                'response_minutes' => (int) ($priority['response_time_minutes'] ?? 0),
                'resolution_minutes' => (int) ($priority['resolution_time_minutes'] ?? 0),
            ], $reference['priorities'] ?? []),
            'categories' => array_map(fn (array $category): array => [
                'id' => (int) $category['id'],
                'label' => (string) $category['name'],
            ], $reference['categories'] ?? []),
            'locations' => array_map(fn (array $location): array => [
                'id' => (int) $location['id'],
                'label' => $this->buildReferenceLabel([
                    (string) ($location['name'] ?? ''),
                    (string) ($location['building'] ?? ''),
                    (string) ($location['room'] ?? ''),
                ]),
            ], $reference['locations'] ?? []),
            'assets' => array_map(fn (array $asset): array => [
                'id' => (int) $asset['id'],
                'location_id' => (int) ($asset['location_id'] ?? 0),
                'label' => (string) ($asset['asset_code'] ?? '') . ' - ' . (string) ($asset['name'] ?? ''),
            ], $reference['assets'] ?? []),
            'impactOptions' => $this->enumOptions(severity_values()),
            'urgencyOptions' => $this->enumOptions(severity_values()),
            'prefill' => [
                'has_asset' => $prefillAsset !== null,
                'asset_label' => $prefillAsset !== null ? (string) ($prefillAsset['label'] ?? '') : '',
                'source_ticket_id' => (int) ($prefill['source_ticket_id'] ?? 0),
                'source_ticket_no' => (string) ($prefill['source_ticket_no'] ?? ''),
            ],
            'defaults' => [
                'title' => (string) ($oldInput['title'] ?? ($prefill['title'] ?? '')),
                'description' => (string) ($oldInput['description'] ?? ($prefill['description'] ?? '')),
                'priority_id' => (string) ($oldInput['priority_id'] ?? ($prefill['priority_id'] ?? '')),
                'ticket_category_id' => (string) ($oldInput['ticket_category_id'] ?? ($prefill['ticket_category_id'] ?? '')),
                'location_id' => (string) ($oldInput['location_id'] ?? ($prefillAsset !== null ? (string) ($prefillAsset['location_id'] ?? '') : (string) ($prefill['location_id'] ?? ''))),
                'asset_id' => (string) ($oldInput['asset_id'] ?? ($prefillAsset !== null ? (string) ($prefillAsset['id'] ?? '') : (string) ($prefill['asset_id'] ?? ''))),
                'impact_level' => (string) ($oldInput['impact_level'] ?? ($prefill['impact_level'] ?? 'medium')),
                'urgency_level' => (string) ($oldInput['urgency_level'] ?? ($prefill['urgency_level'] ?? 'medium')),
                'submission_token' => $this->submissionToken((string) ($oldInput['submission_token'] ?? '')),
                'requester_name' => (string) ($viewer['full_name'] ?? ''),
                'requester_email' => (string) ($viewer['email'] ?? ''),
            ],
        ];
    }

    /**
     * สร้าง ticket ใหม่จากฟอร์มแจ้งซ่อม (validate input+ไฟล์แนบ → เขียน DB → แจ้งเตือน) แล้วคืน id ของ ticket.
     * สถานะเริ่มต้น: status = pending_approval, approval_status = pending.
     * ผลข้างเคียง (ผ่าน repository, อยู่ใน transaction เดียว): INSERT แถว tickets + คำขออนุมัติ (ticket_approvals ให้ผู้อนุมัติเริ่มต้น)
     *   + SLA tracking 2 แถว (response/resolution) + activity log (ticket_submitted); เก็บไฟล์แนบลงดิสก์และตาราง attachments
     *   เฉพาะเมื่อสร้างใบใหม่จริง. ถ้าเป็นฝ่ายเปิด transaction เอง: commit แล้วยิงแจ้งเตือน 'ticket.created' (in-app + คิวอีเมล)
     *   ให้ผู้อนุมัติที่ใช้งานอยู่ แบบ best-effort (error ถูกกลืน). ถ้าล้มเหลว: rollback + ลบไฟล์ที่เพิ่งเก็บทิ้ง.
     * idempotency: submission_token ที่เคยสร้างไปแล้วจะคืน id ใบเดิม (ไม่สร้างซ้ำ, ไม่เก็บไฟล์, ไม่แจ้งเตือน).
     * transaction ที่ครอบมาก่อน (เช่น guest-convert): เมธอดนี้จะร่วมใช้ transaction เดิม — ผู้เรียกต้อง commit และยิงแจ้งเตือนเอง.
     * @param array<string, mixed> $viewer ต้องมี 'id' (>0 = ผู้แจ้ง); ใช้ 'department_id' เป็นแผนกผู้แจ้งถ้ามี
     * @param array<string, mixed> $input ต้องมี 'title' (ไม่ว่าง, ≤200 ตัวอักษร), 'description' (ไม่ว่าง),
     *   'priority_id'/'ticket_category_id'/'location_id' (id ที่มีจริง), 'asset_id' (0=ไม่ระบุ; ถ้า >0 ต้องอยู่ใน location เดียวกัน),
     *   'impact_level'/'urgency_level' (ค่าใน severity_values()), 'submission_token' (token ที่ valid — กันกดส่งซ้ำ)
     * @param array<string, mixed> $files ไฟล์แนบรูปแบบ $_FILES (validate ก่อนเก็บ)
     * @param string $channel ช่องทางที่มา — ผู้เรียกตั้งเองเท่านั้น (web/qr/phone/email/walk_in; ค่าที่ไม่รู้จัก→'web'); ห้ามอ่านจาก input ของผู้ใช้
     * @return int id ของ ticket (ใบใหม่ หรือใบเดิมกรณี submission_token ซ้ำ)
     * @throws DomainException เมื่อ title/description ว่าง, ไม่มี viewer id, title เกิน 200, impact/urgency ผิด,
     *   priority/category/location/asset ไม่ถูกต้องหรือ asset ไม่อยู่ใน location, หรือ submission_token หมดอายุ
     */
    public function createTicket(array $viewer, array $input, array $files = [], string $channel = 'web'): int
    {
        $validatedFiles = $this->attachments->validateUploads($files);
        $reference = $this->reads->getCreateFormReferenceData();

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        // ใช้ strict_int เพื่อให้ค่าผิดรูปอย่าง "1junk" ถูกปฏิเสธตั้งแต่ต้น ไม่ใช่แอบแปลงเป็นเลขนำหน้า (1) เงียบ ๆ
        $priorityId = strict_int($input['priority_id'] ?? null, 'Priority ');
        $categoryId = strict_int($input['ticket_category_id'] ?? null, 'Category ');
        $locationId = strict_int($input['location_id'] ?? null, 'Location ');
        $assetId = strict_int($input['asset_id'] ?? null, 'Asset ');
        $impactLevel = strtolower(trim((string) ($input['impact_level'] ?? 'medium')));
        $urgencyLevel = strtolower(trim((string) ($input['urgency_level'] ?? 'medium')));
        // channel (ช่องทางที่มา) เป็นค่าที่เชื่อถือได้เพราะผู้เรียกเป็นคนตั้งเอง (web controller → ค่าเริ่มต้น 'web'; guest QR
        // convert → 'qr') ห้ามอ่านจาก $input ของผู้ใช้เด็ดขาด — ไม่งั้น web POST ที่ถูกปั้นขึ้นมาอาจปลอมแหล่งที่มา
        // เป็น channel=phone/email/walk_in แล้วทำให้ข้อมูลแหล่งที่มาในหน้ารายละเอียดและรายงานเพี้ยน.
        $channel = in_array($channel, ['web', 'qr', 'phone', 'email', 'walk_in'], true) ? $channel : 'web';
        $submissionToken = $this->submissionToken((string) ($input['submission_token'] ?? ''), false);

        if ($title === '' || $description === '') {
            throw new DomainException('กรุณากรอกหัวข้อและรายละเอียดของปัญหาให้ครบถ้วน');
        }

        if ((int) ($viewer['id'] ?? 0) <= 0) {
            throw new DomainException('ไม่พบข้อมูลผู้ใช้งานสำหรับสร้างรายการแจ้งซ่อม');
        }

        $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($titleLength > 200) {
            throw new DomainException('หัวข้อปัญหาต้องไม่เกิน 200 ตัวอักษร');
        }

        if (!in_array($impactLevel, severity_values(), true) || !in_array($urgencyLevel, severity_values(), true)) {
            throw new DomainException('ค่าผลกระทบหรือความเร่งด่วนไม่ถูกต้อง');
        }

        $priority = $this->findReferenceById($reference['priorities'] ?? [], $priorityId);
        $category = $this->findReferenceById($reference['categories'] ?? [], $categoryId);
        $location = $this->findReferenceById($reference['locations'] ?? [], $locationId);

        if ($priority === null || $category === null || $location === null) {
            throw new DomainException('กรุณาเลือก Priority, Category และ Location ให้ถูกต้อง');
        }

        $asset = null;
        if ($assetId > 0) {
            $asset = $this->findReferenceById($reference['assets'] ?? [], $assetId);
            if ($asset === null) {
                throw new DomainException('Asset ที่เลือกไม่ถูกต้อง');
            }

            if ((int) ($asset['location_id'] ?? 0) !== $locationId) {
                throw new DomainException('Asset ที่เลือกไม่ได้อยู่ใน Location ที่ระบุ');
            }
        }

        $requestedAt = date('Y-m-d H:i:s');
        $categorySla = setting('category_sla_' . $categoryId, []);
        $responseMinutes = max(0, (int) ($categorySla['response_minutes'] ?? (int) ($priority['response_time_minutes'] ?? 0)));
        $resolutionMinutes = max(0, (int) ($categorySla['resolution_minutes'] ?? (int) ($priority['resolution_time_minutes'] ?? 0)));
        $responseDueAt = date('Y-m-d H:i:s', strtotime($requestedAt . ' +' . $responseMinutes . ' minutes'));
        $resolutionDueAt = date('Y-m-d H:i:s', strtotime($requestedAt . ' +' . $resolutionMinutes . ' minutes'));

        $storedPaths = [];
        $ticketId = 0;
        $created = false;
        // ถ้าผู้เรียกเปิด transaction ครอบไว้อยู่แล้ว ก็ร่วมใช้ transaction นั้น (guest-request convert ครอบ
        // การสร้าง ticket กับ claim/link ให้เป็นก้อน atomic เดียว); เราจะ commit/rollback/notify เองเฉพาะตอนที่เป็นคนเริ่ม transaction เท่านั้น.
        $startedTransaction = !$this->db->inTransaction();

        try {
            // RISK MAP: การ insert ticket กับแถว/ไฟล์แนบต้องอยู่เป็นก้อน atomic เดียว; ทุกครั้งที่ rollback ต้องล้าง storedPaths ตามไปด้วย.
            if ($startedTransaction) {
                $this->db->beginTransaction();
            }
            $result = $this->tickets->createTicket([
                'submission_token' => $submissionToken,
                'title' => $title,
                'description' => $description,
                'requester_id' => (int) ($viewer['id'] ?? 0),
                'requester_department_id' => isset($viewer['department_id']) ? (int) $viewer['department_id'] : null,
                'location_id' => $locationId,
                'asset_id' => is_array($asset) ? (int) ($asset['id'] ?? 0) : null,
                'ticket_category_id' => $categoryId,
                'priority_id' => $priorityId,
                'channel' => $channel,
                'impact_level' => $impactLevel,
                'urgency_level' => $urgencyLevel,
                'requested_at' => $requestedAt,
                'response_due_at' => $responseDueAt,
                'resolution_due_at' => $resolutionDueAt,
            ]);

            $ticketId = (int) ($result['id'] ?? 0);
            $created = (bool) ($result['created'] ?? false);

            if ($created) {
                $storedPaths = $this->attachments->storeValidated($validatedFiles, $ticketId, (int) ($viewer['id'] ?? 0));
            }

            if ($startedTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // ย้อนคืน (ลบ) ไฟล์ที่เก็บไว้สำหรับ ticket ที่ล้มเหลวนี้; ถ้าลบไฟล์ไหนไม่ได้ให้ log ไว้
            $this->attachments->purgeStoredFiles($storedPaths, 'ticket.create.cleanup', ['ticket' => $ticketId]);
            throw $exception;
        }

        // ตอนร่วมใช้ transaction ที่ครอบอยู่ ticket จะยังไม่ถูกบันทึกจริงจนกว่าผู้เรียกจะ commit — ผู้เรียก
        // เลยเป็นฝ่ายส่งแจ้งเตือนเอง. เราจะแจ้งเตือนตรงนี้เฉพาะตอนที่ commit ไปข้างบนแล้วเท่านั้น.
        if ($created && $startedTransaction) {
            try {
                $this->notifications->notifyTicketEvent($ticketId, 'ticket.created', (int) ($viewer['id'] ?? 0));
            } catch (Throwable) {
            }
        }

        return $ticketId;
    }

    /**
     * เตรียม view-model ฟอร์มแจ้งซ่อมใหม่โดย prefill จาก ticket ต้นทาง (ปุ่ม "ทำซ้ำ").
     * เช็คสิทธิ์ให้แล้ว: ต้องมองเห็น ticket ($viewer) และ policy อนุญาต duplicate — ผู้เรียกไม่ต้องเช็คซ้ำ.
     * @param array<string, mixed> $viewer ต้องมี 'id'/'role' (ใช้เช็คการมองเห็น + สิทธิ์ duplicate)
     * @return array<string, mixed> view-model ฟอร์ม (เหมือน getCreateFormData แต่ prefill จาก ticket ต้นทาง)
     * @throws DomainException เมื่อไม่พบ ticket, มองไม่เห็น, หรือไม่มีสิทธิ์ทำซ้ำ
     */
    public function getDuplicateFormData(int $ticketId, array $viewer): array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null || !$this->policy->canDuplicateTicket($ticket, $viewer)) {
            throw new DomainException('Ticket นี้ไม่สามารถใช้เปิดรายการใหม่ได้');
        }

        return $this->getCreateFormData($viewer, [], [
            'source_ticket_id' => $ticketId,
            'source_ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
            'title' => (string) ($ticket['title'] ?? ''),
            'description' => (string) ($ticket['description'] ?? ''),
            'priority_id' => (string) ($ticket['priority_id'] ?? ''),
            'ticket_category_id' => (string) ($ticket['ticket_category_id'] ?? ''),
            'location_id' => (string) ($ticket['location_id'] ?? ''),
            'asset_id' => (string) ($ticket['asset_id'] ?? ''),
            'impact_level' => (string) ($ticket['impact_level'] ?? 'medium'),
            'urgency_level' => (string) ($ticket['urgency_level'] ?? 'medium'),
        ]);
    }

    /**
     * ข้อมูลสถานะแบบเบา ๆ สำหรับ live poll ในหน้า ticket detail — status กับจำนวน comment ที่ผู้ดูเห็น.
     * คืน null ถ้าไม่มีสิทธิ์เห็น ticket (ใช้ visibility เดียวกับ getTicketDetailData).
     *
     * @return array{status: string, comment_count: int}|null
     */
    public function getTicketLiveState(int $ticketId, array $viewer): ?array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $includeInternal = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        return [
            'status' => (string) ($ticket['status'] ?? ''),
            'comment_count' => $this->comments->countForTicket($ticketId, $includeInternal),
        ];
    }

    /**
     * comment ใหม่ (id > afterId) ที่ผู้ดูเห็น — สำหรับ live-append แบบแชทในหน้า ticket detail.
     * map เหมือน getTicketDetailData (reuse mapComment กับ attachments). คืน null ถ้าไม่มีสิทธิ์เห็น ticket.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getNewComments(int $ticketId, array $viewer, int $afterId): ?array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $includeInternal = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        // ดึงเฉพาะ comment ที่ใหม่กว่า $afterId ไม่ได้ดึงทั้ง thread มากรองใน PHP. ตอน poll แล้วไม่มีอะไรเปลี่ยน
        // (ไม่มี comment ใหม่ — เจอบ่อยเพราะ poll ทุก 20 วินาที) จะ return ออกก่อนโดยไม่แตะไฟล์แนบ
        // ticket ที่เงียบ ๆ เลยเสียแค่ query เดียวที่จำกัดขอบเขต ไม่ต้องสแกนทุก comment กับไฟล์แนบ.
        $comments = $this->comments->getCommentsAfterId($ticketId, $afterId, $includeInternal);
        if ($comments === []) {
            return [];
        }

        $commentIds = array_map(static fn (array $comment): int => (int) ($comment['id'] ?? 0), $comments);
        $commentAttachments = [];
        foreach ($this->attachments->getAttachmentsForCommentIds($commentIds, $includeInternal) as $attachment) {
            $commentAttachments[(int) ($attachment['comment_id'] ?? 0)][] = $attachment;
        }

        $new = [];
        foreach ($comments as $comment) {
            $mapped = $this->mapComment($comment, $viewer);
            $mapped['attachments'] = $commentAttachments[(int) ($comment['id'] ?? 0)] ?? [];
            $new[] = $mapped;
        }

        return $new;
    }

    /**
     * view-model ของหน้ารายละเอียด ticket: ข้อมูล ticket + ไฟล์แนบ + comment (พร้อมไฟล์แนบต่อ comment) + activity log + workflow.
     * เช็ค visibility ให้แล้ว — คืน null ถ้าผู้ดูไม่มีสิทธิ์เห็น ticket นี้. requester จะไม่เห็น comment ภายใน (internal).
     * @param array<string, mixed> $viewer ต้องมี 'id'/'role' (คุม visibility + ซ่อน comment ภายในจาก requester)
     * @param array<string, mixed> $oldInput ค่าฟอร์ม workflow ที่ค้างไว้ (repopulate หลัง validation fail)
     * @return array<string, mixed>|null view-model, หรือ null เมื่อมองไม่เห็น ticket
     */
    public function getTicketDetailData(int $ticketId, array $viewer, array $oldInput = []): ?array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $includeInternal = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        $allAttachments = $this->attachments->getTicketAttachments($ticketId, $includeInternal);
        $commentAttachments = [];
        foreach ($allAttachments as $attachment) {
            $commentAttachments[(int) ($attachment['comment_id'] ?? 0)][] = $attachment;
        }

        return [
            'ticket' => $this->mapTicketDetail($ticket),
            'attachments' => $commentAttachments[0] ?? [],
            'comments' => array_map(function (array $comment) use ($viewer, $commentAttachments): array {
                $mapped = $this->mapComment($comment, $viewer);
                $mapped['attachments'] = $commentAttachments[(int) ($comment['id'] ?? 0)] ?? [];
                return $mapped;
            }, $this->comments->getCommentsByTicketId($ticketId, $includeInternal)),
            'activityLogs' => array_map(fn (array $log): array => $this->mapActivityLog($log), $this->reads->getActivityLogsByTicketId($ticketId)),
            'workflow' => $this->buildWorkflowData($ticket, $viewer, $oldInput),
        ];
    }

    /**
     * ticket ล่าสุดของ asset หนึ่ง (สำหรับหน้ารายละเอียด asset) พร้อมจำนวนรวมทั้งหมด. อ่านอย่างเดียว.
     * assetId ≤ 0 คืนผลว่าง (tickets=[], total=0) โดยไม่ query.
     * @return array<string, mixed> รูปแบบ {tickets: <รายการ ticket summary>, total: int}
     */
    public function getRecentTicketsForAsset(int $assetId, int $limit = 10): array
    {
        if ($assetId <= 0) {
            return [
                'tickets' => [],
                'total' => 0,
            ];
        }

        $rows = $this->reads->findRecentTicketsByAssetId($assetId, $limit);
        $total = $this->reads->countTicketsByAssetId($assetId);

        return [
            'tickets' => array_map(fn (array $row): array => $this->mapTicketSummary($row), $rows),
            'total' => $total,
        ];
    }

    private function formatMetrics(array $metrics): array
    {
        return [
            'total' => (int) ($metrics['total_tickets'] ?? 0),
            'pendingApproval' => (int) ($metrics['pending_approval_tickets'] ?? 0),
            'inProgress' => (int) ($metrics['active_work_tickets'] ?? 0),
            'completedThisMonth' => (int) ($metrics['completed_this_month_tickets'] ?? 0),
            'overdue' => (int) ($metrics['overdue_tickets'] ?? 0),
        ];
    }

    private function mapTicketSummary(array $ticket): array
    {
        $priorityCode = strtoupper((string) ($ticket['priority_code'] ?? 'MEDIUM'));
        $status = (string) ($ticket['status'] ?? 'submitted');
        $approvalStatus = (string) ($ticket['approval_status'] ?? 'pending');
        $sla = $this->buildSlaSummary($ticket);

        return [
            'id' => (int) ($ticket['id'] ?? 0),
            'ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
            'title' => (string) ($ticket['title'] ?? ''),
            'status' => $status,
            'status_label' => ticket_status_label_th($status),
            'status_tone' => $this->statusTone($status),
            'approval_status' => $approvalStatus,
            'approval_label' => approval_label_th($approvalStatus),
            'approval_tone' => $this->approvalTone($approvalStatus),
            'priority_code' => $priorityCode,
            'priority_label' => in_array($priorityCode, ['LOW', 'MEDIUM', 'HIGH', 'URGENT'], true)
                ? priority_label_th($priorityCode)
                : (string) ($ticket['priority_name'] ?? $priorityCode),
            'priority_tone' => $this->priorityTone($priorityCode),
            'location_name' => (string) ($ticket['location_name'] ?? '-'),
            'requester_name' => (string) ($ticket['requester_name'] ?? '-'),
            'technician_name' => (string) ($ticket['technician_name'] ?? '-'),
            'category_name' => (string) ($ticket['category_name'] ?? '-'),
            'channel_label' => channel_label_th((string) ($ticket['channel'] ?? 'web')),
            'requested_at' => $this->formatDateTime($ticket['requested_at'] ?? null),
            'updated_at' => $this->formatDateTime($ticket['updated_at'] ?? null),
            'response_due_at' => $this->formatDateTime($ticket['response_due_at'] ?? null),
            'resolution_due_at' => $this->formatDateTime($ticket['resolution_due_at'] ?? null),
            'sla_overview_label' => (string) ($sla['label'] ?? '-'),
            'sla_overview_tone' => (string) ($sla['tone'] ?? 'default'),
            'sla_response_label' => (string) (($sla['response']['label'] ?? '-')),
            'sla_resolution_label' => (string) (($sla['resolution']['label'] ?? '-')),
            'is_overdue' => (bool) ($sla['is_overdue'] ?? false),
        ];
    }

    /** Public: การ map ข้อมูลรายละเอียด ticket ที่ใช้ร่วมกันโดย show() และ TicketPrintService. */
    public function mapTicketDetail(array $ticket): array
    {
        $mapped = $this->mapTicketSummary($ticket);
        $sla = $this->buildSlaSummary($ticket);

        return $mapped + [
            'description' => (string) ($ticket['description'] ?? ''),
            'assigned_manager_id' => (int) ($ticket['assigned_manager_id'] ?? 0),
            'assigned_technician_id' => (int) ($ticket['assigned_technician_id'] ?? 0),
            'work_order_no' => (string) ($ticket['work_order_no'] ?? ''),
            'work_order_status' => (string) ($ticket['work_order_status'] ?? ''),
            'work_order_status_label' => work_order_status_label_th((string) ($ticket['work_order_status'] ?? '')),
            'work_order_instructions' => (string) ($ticket['work_order_instructions'] ?? ''),
            'work_order_diagnosis_summary' => (string) ($ticket['work_order_diagnosis_summary'] ?? ''),
            'work_order_resolution_summary' => (string) ($ticket['work_order_resolution_summary'] ?? ''),
            'work_order_labor_minutes' => (int) ($ticket['work_order_labor_minutes'] ?? 0),
            'impact_level' => severity_label_th((string) ($ticket['impact_level'] ?? 'medium')),
            'urgency_level' => severity_label_th((string) ($ticket['urgency_level'] ?? 'medium')),
            'requester_email' => (string) ($ticket['requester_email'] ?? '-'),
            'requester_phone' => (string) ($ticket['requester_phone'] ?? '-'),
            'manager_name' => (string) ($ticket['manager_name'] ?? '-'),
            'asset_code' => (string) ($ticket['asset_code'] ?? '-'),
            'asset_name' => (string) ($ticket['asset_name'] ?? '-'),
            'location_detail' => $this->buildLocationDetail($ticket),
            'approved_at' => $this->formatDateTime($ticket['approved_at'] ?? null),
            'assigned_at' => $this->formatDateTime($ticket['assigned_at'] ?? null),
            'started_at' => $this->formatDateTime($ticket['started_at'] ?? null),
            'first_response_at' => $this->formatDateTime($ticket['first_response_at'] ?? null),
            'resolved_at' => $this->formatDateTime($ticket['resolved_at'] ?? null),
            'completed_at' => $this->formatDateTime($ticket['completed_at'] ?? null),
            'cancelled_at' => $this->formatDateTime($ticket['cancelled_at'] ?? null),
            'closed_at' => $this->formatDateTime($ticket['closed_at'] ?? null),
            'response_due_at' => $this->formatDateTime($ticket['response_due_at'] ?? null),
            'work_order_assigned_at' => $this->formatDateTime($ticket['work_order_assigned_at'] ?? null),
            'work_order_accepted_at' => $this->formatDateTime($ticket['work_order_accepted_at'] ?? null),
            'work_order_started_at' => $this->formatDateTime($ticket['work_order_started_at'] ?? null),
            'work_order_completed_at' => $this->formatDateTime($ticket['work_order_completed_at'] ?? null),
            'resolution_summary' => (string) ($ticket['resolution_summary'] ?? ''),
            'closure_note' => (string) ($ticket['closure_note'] ?? ''),
            'rating_score' => (int) ($ticket['rating_score'] ?? 0),
            'rating_feedback' => (string) ($ticket['rating_feedback'] ?? ''),
            'rating_created_at' => $this->formatDateTime($ticket['rating_created_at'] ?? null),
            'sla_overview' => $sla,
            'sla_response' => $sla['response'],
            'sla_resolution' => $sla['resolution'],
            'print_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print',
            'print_a5_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print?paper=a5',
            'print_pdf_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print/pdf',
        ];
    }

    private function buildWorkflowData(array $ticket, array $viewer, array $oldInput): array
    {
        $managerCanAct = $this->policy->canManageWorkflow($ticket, $viewer);
        $canReview = $managerCanAct && $this->policy->canReviewTicket($ticket, $viewer);
        $canAssign = $managerCanAct && $this->policy->canAssignTicket($ticket, $viewer);
        $technicianCanAct = $this->policy->canTechnicianWork($ticket, $viewer);
        $canAccept = $technicianCanAct && $this->policy->canAcceptTechnicianWork($ticket, $viewer);
        $canStart = $technicianCanAct && $this->policy->canStartTechnicianWork($ticket, $viewer);
        $canResolve = $technicianCanAct && $this->policy->canResolveTechnicianWork($ticket, $viewer);
        $requesterCanAct = $this->policy->canRequesterManageClosure($ticket, $viewer);
        $canComplete = $requesterCanAct && $this->policy->canRequesterCompleteTicket($ticket, $viewer);
        $canReopen = $requesterCanAct && $this->policy->canRequesterReopenTicket($ticket, $viewer);
        $canCancel = $requesterCanAct && $this->policy->canRequesterCancelTicket($ticket, $viewer);
        $canDuplicate = $this->policy->canDuplicateTicket($ticket, $viewer);
        $canComment = (int) ($viewer['id'] ?? 0) > 0;
        $canUseInternalComment = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        return [
            'managerCanAct' => $managerCanAct,
            'canReview' => $canReview,
            'canAssign' => $canAssign,
            'technicianCanAct' => $technicianCanAct,
            'canAccept' => $canAccept,
            'canStart' => $canStart,
            'canResolve' => $canResolve,
            'requesterCanAct' => $requesterCanAct,
            'canComplete' => $canComplete,
            'canReopen' => $canReopen,
            'canCancel' => $canCancel,
            'canDuplicate' => $canDuplicate,
            'canComment' => $canComment,
            'canUseInternalComment' => $canUseInternalComment,
            'technicians' => array_map(fn (array $technician): array => [
                'id' => (int) ($technician['id'] ?? 0),
                'label' => (string) ($technician['full_name'] ?? '-'),
            ], $this->reads->getActiveTechnicians()),
            'workOrder' => [
                'number' => (string) ($ticket['work_order_no'] ?? ''),
                'status' => work_order_status_label_th((string) ($ticket['work_order_status'] ?? '')),
                'instructions' => (string) ($ticket['work_order_instructions'] ?? ''),
                'diagnosis_summary' => (string) ($ticket['work_order_diagnosis_summary'] ?? ''),
                'resolution_summary' => (string) ($ticket['work_order_resolution_summary'] ?? ''),
                'labor_minutes' => (int) ($ticket['work_order_labor_minutes'] ?? 0),
                'assigned_at' => $this->formatDateTime($ticket['work_order_assigned_at'] ?? null),
                'accepted_at' => $this->formatDateTime($ticket['work_order_accepted_at'] ?? null),
                'started_at' => $this->formatDateTime($ticket['work_order_started_at'] ?? null),
                'completed_at' => $this->formatDateTime($ticket['work_order_completed_at'] ?? null),
            ],
            'rating' => [
                'score' => (int) ($ticket['rating_score'] ?? 0),
                'feedback' => (string) ($ticket['rating_feedback'] ?? ''),
                'created_at' => $this->formatDateTime($ticket['rating_created_at'] ?? null),
            ],
            'defaults' => [
                'note' => (string) ($oldInput['note'] ?? ''),
                'technician_id' => (string) ($oldInput['technician_id'] ?? (string) ($ticket['assigned_technician_id'] ?? '')),
                'instructions' => (string) ($oldInput['instructions'] ?? ''),
                'accept_note' => (string) ($oldInput['accept_note'] ?? ''),
                'start_note' => (string) ($oldInput['start_note'] ?? ''),
                'diagnosis_summary' => (string) ($oldInput['diagnosis_summary'] ?? (string) ($ticket['work_order_diagnosis_summary'] ?? '')),
                'resolution_summary' => (string) ($oldInput['resolution_summary'] ?? (string) ($ticket['work_order_resolution_summary'] ?? '')),
                'labor_minutes' => (string) ($oldInput['labor_minutes'] ?? (string) ((int) ($ticket['work_order_labor_minutes'] ?? 0))),
                'closure_note' => (string) ($oldInput['closure_note'] ?? (string) ($ticket['closure_note'] ?? '')),
                'reopen_note' => (string) ($oldInput['reopen_note'] ?? ''),
                'cancel_note' => (string) ($oldInput['cancel_note'] ?? ''),
                'score' => (string) ($oldInput['score'] ?? (string) ((int) ($ticket['rating_score'] ?? 0))),
                'feedback' => (string) ($oldInput['feedback'] ?? (string) ($ticket['rating_feedback'] ?? '')),
                'comment_body' => (string) ($oldInput['comment_body'] ?? ''),
                'has_comment_body_old_input' => array_key_exists('comment_body', $oldInput),
                'comment_is_internal' => (string) ($oldInput['comment_is_internal'] ?? ''),
                'comment_submission_token' => $this->submissionToken((string) ($oldInput['comment_submission_token'] ?? '')),
                'has_comment_is_internal_old_input' => array_key_exists('comment_is_internal', $oldInput),
                'editing_comment_id' => (string) ($oldInput['editing_comment_id'] ?? ''),
            ],
        ];
    }

    private function mapComment(array $comment, array $viewer): array
    {
        $isInternal = (bool) ($comment['is_internal'] ?? false);

        return [
            'id' => (int) ($comment['id'] ?? 0),
            'user_id' => (int) ($comment['user_id'] ?? 0),
            'is_internal' => $isInternal,
            'author_name' => (string) ($comment['author_name'] ?? 'Unknown'),
            'author_role' => role_label_th((string) ($comment['author_role'] ?? 'user')),
            'body' => (string) ($comment['body'] ?? ''),
            'visibility_label' => $isInternal ? 'ภายใน' : 'สาธารณะ',
            'visibility_tone' => $isInternal ? 'warning' : 'default',
            'created_at' => $this->formatDateTime($comment['created_at'] ?? null),
            // integer version สำหรับ optimistic-lock ตอนแก้ไข (มาจาก hidden original_version) — กันเคส lost update
            // ในวินาทีเดียวกันที่ token แบบ updated_at (ละเอียดแค่วินาที) จับไม่ได้ (WHERE version = :original_version)
            'version' => (int) ($comment['version'] ?? 1),
            'can_manage' => $this->canManageComment($comment, $viewer),
        ];
    }

    private function mapActivityLog(array $log): array
    {
        $action = (string) ($log['action'] ?? 'updated');

        return [
            'actor_name' => (string) ($log['actor_name'] ?? 'System'),
            'actor_role' => role_label_th((string) ($log['actor_role'] ?? 'system')),
            'action_label' => $this->activityActionLabel($action),
            'action_tone' => $this->activityActionTone($action),
            'details' => (string) ($log['details'] ?? ''),
            'from_status' => ticket_status_label_th((string) ($log['from_status'] ?? '')),
            'to_status' => ticket_status_label_th((string) ($log['to_status'] ?? '')),
            'created_at' => $this->formatDateTime($log['created_at'] ?? null),
        ];
    }

    private function activityActionLabel(string $action): string
    {
        return match ($action) {
            'ticket_submitted' => 'สร้างรายการ',
            'ticket_approved' => 'อนุมัติรายการ',
            'ticket_rejected' => 'ปฏิเสธรายการ',
            'technician_assigned' => 'มอบหมายช่าง',
            'work_accepted' => 'รับงาน',
            'work_started' => 'เริ่มงาน',
            'ticket_resolved' => 'สรุปผลการซ่อม',
            'ticket_completed' => 'ยืนยันปิดงาน',
            'ticket_reopened' => 'ขอแก้งานซ้ำ',
            'ticket_cancelled' => 'ยกเลิกรายการ',
            default => humanize_label($action),
        };
    }

    private function activityActionTone(string $action): string
    {
        return match ($action) {
            'ticket_reopened' => 'warning',
            default => 'default',
        };
    }

    private function formatDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return thai_datetime($timestamp);
    }

    private function buildSlaSummary(array $ticket): array
    {
        if ((string) ($ticket['status'] ?? '') === 'cancelled') {
            $notApplicable = [
                'status' => 'unavailable',
                'label' => 'ไม่คิด SLA',
                'tone' => 'default',
                'target_at' => '-',
                'achieved_at' => '-',
            ];

            return [
                'label' => 'ไม่คิด SLA',
                'tone' => 'default',
                'is_overdue' => false,
                'response' => ['name' => 'Response SLA'] + $notApplicable,
                'resolution' => ['name' => 'Resolution SLA'] + $notApplicable,
            ];
        }

        $requestedAt = $ticket['requested_at'] ?? null;
        $response = $this->buildSlaMetricState(
            'Response SLA',
            $ticket['response_due_at'] ?? null,
            $ticket['first_response_at'] ?? null,
            $requestedAt
        );
        $resolution = $this->buildSlaMetricState(
            'Resolution SLA',
            $ticket['resolution_due_at'] ?? null,
            $ticket['resolved_at'] ?? null,
            $requestedAt
        );

        $isOverdue = ($response['status'] ?? '') === 'breached' || ($resolution['status'] ?? '') === 'breached';

        if (($resolution['status'] ?? '') === 'breached') {
            $label = 'แก้ไขเกินกำหนด';
            $tone = 'danger';
        } elseif (($response['status'] ?? '') === 'breached') {
            $label = 'ตอบรับเกินกำหนด';
            $tone = 'danger';
        } elseif (($response['status'] ?? '') === 'met' && ($resolution['status'] ?? '') === 'met') {
            $label = 'อยู่ใน SLA';
            $tone = 'success';
        } elseif (($response['status'] ?? '') === 'pending') {
            $label = 'รอตอบรับ';
            $tone = 'warning';
        } elseif (($resolution['status'] ?? '') === 'pending') {
            $label = 'รอแก้ไข';
            $tone = 'info';
        } else {
            $label = 'กำลังติดตาม SLA';
            $tone = 'default';
        }

        return [
            'label' => $label,
            'tone' => $tone,
            'is_overdue' => $isOverdue,
            'response' => $response,
            'resolution' => $resolution,
        ];
    }

    private function buildSlaMetricState(string $name, mixed $targetAt, mixed $achievedAt, mixed $requestedAt = null): array
    {
        $target = is_string($targetAt) ? trim($targetAt) : '';
        $achieved = is_string($achievedAt) ? trim($achievedAt) : '';
        $requested = is_string($requestedAt) ? trim($requestedAt) : '';
        $targetTimestamp = $target !== '' ? strtotime($target) : false;
        $achievedTimestamp = $achieved !== '' ? strtotime($achieved) : false;
        $requestedTimestamp = $requested !== '' ? strtotime($requested) : false;

        if ($targetTimestamp === false) {
            return [
                'name' => $name,
                'status' => 'unavailable',
                'label' => 'ยังไม่กำหนด',
                'tone' => 'default',
                'target_at' => '-',
                'achieved_at' => $this->formatDateTime($achievedAt),
            ];
        }

        if ($achievedTimestamp !== false) {
            // งานที่ทำเสร็จก่อนเวลาที่แจ้ง ticket เป็นข้อมูลที่เป็นไปไม่ได้ มักมาจาก seed หรือ import ผิด — จะตัดสิน
            // ว่าตรงเวลาหรือเกินกำหนดก็ไม่ได้ เลยถือว่า SLA ใช้งานไม่ได้ ดีกว่าไปบอกว่า "met" แบบผิด ๆ. ทำให้การจัดหมวด
            // ในหน้ารายละเอียด ticket นี้ตรงกับของรายงาน (ReportService::buildSlaMetricState).
            if ($requestedTimestamp !== false && $achievedTimestamp < $requestedTimestamp) {
                return [
                    'name' => $name,
                    'status' => 'unavailable',
                    'label' => 'ข้อมูลเวลาไม่ถูกต้อง',
                    'tone' => 'default',
                    'target_at' => $this->formatDateTime($targetAt),
                    'achieved_at' => $this->formatDateTime($achievedAt),
                ];
            }

            $isBreached = $achievedTimestamp > $targetTimestamp;

            return [
                'name' => $name,
                'status' => $isBreached ? 'breached' : 'met',
                'label' => $isBreached ? 'เกินกำหนด' : 'ตรงเวลา',
                'tone' => $isBreached ? 'danger' : 'success',
                'target_at' => $this->formatDateTime($targetAt),
                'achieved_at' => $this->formatDateTime($achievedAt),
            ];
        }

        $isBreached = $targetTimestamp < time();

        return [
            'name' => $name,
            'status' => $isBreached ? 'breached' : 'pending',
            'label' => $isBreached ? 'เกินกำหนด' : 'รอดำเนินการ',
            'tone' => $isBreached ? 'danger' : 'warning',
            'target_at' => $this->formatDateTime($targetAt),
            'target_ts' => $targetTimestamp, // Unix epoch สำหรับ countdown ฝั่ง client โดยไม่ต้องห่วง timezone
            'achieved_at' => '-',
        ];
    }


    private function buildLocationDetail(array $ticket): string
    {
        $parts = array_filter([
            (string) ($ticket['location_name'] ?? ''),
            (string) ($ticket['building'] ?? ''),
            (string) ($ticket['floor'] ?? ''),
            (string) ($ticket['room'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        return $parts !== [] ? implode(' / ', $parts) : '-';
    }

    private function buildReferenceLabel(array $parts): string
    {
        $segments = array_values(array_filter($parts, static fn (string $value): bool => trim($value) !== ''));

        return $segments !== [] ? implode(' / ', $segments) : '-';
    }

    private function normalizeDashboardFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $status = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        $preset = is_string($filters['preset'] ?? null) ? trim((string) $filters['preset']) : '';
        $year = (int) ($filters['year'] ?? date('Y'));

        $allowedStatuses = ticket_status_values();

        $currentYear = (int) date('Y');
        if ($year < 2020 || $year > ($currentYear + 1)) {
            $year = $currentYear;
        }

        return normalize_date_range($fromDate, $toDate) + [
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
            'year' => $year,
            'preset' => in_array($preset, ['mine', 'overdue', 'pending_approval', 'today'], true) ? $preset : '',
        ];
    }

    private function buildDashboardFilterData(array $filters, array $reference): array
    {
        $yearRows = $reference['years'] ?? [];
        $years = array_map(
            fn (array $row): int => (int) ($row['report_year'] ?? 0),
            array_filter($yearRows, static fn (array $row): bool => (int) ($row['report_year'] ?? 0) > 0)
        );

        if ($years === []) {
            $years = [(int) date('Y')];
        }

        return [
            'selected' => [
                'from_date' => (string) ($filters['from_date'] ?? ''),
                'to_date' => (string) ($filters['to_date'] ?? ''),
                'department_id' => (string) ($filters['department_id'] ?? ''),
                'category_id' => (string) ($filters['category_id'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'year' => (string) ($filters['year'] ?? date('Y')),
                'preset' => (string) ($filters['preset'] ?? ''),
            ],
            'departmentOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกแผนก'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['departments'] ?? [])),
            'categoryOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกหมวด'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['categories'] ?? [])),
            'statusOptions' => ticket_status_options(true),
            'yearOptions' => array_map(fn (int $year): array => [
                'value' => (string) $year,          // ค่าที่ใช้ query ยังเป็นปี ค.ศ.
                'label' => thai_year($year),        // แสดงผลเป็นปี พ.ศ.
            ], $years),
            'active_count' => $this->countActiveDashboardFilters($filters),
        ];
    }

    private function countActiveDashboardFilters(array $filters): int
    {
        $count = 0;

        foreach (['from_date', 'to_date', 'department_id', 'category_id', 'status', 'preset'] as $key) {
            $value = $filters[$key] ?? '';
            if (is_string($value) && trim($value) !== '') {
                $count++;
                continue;
            }

            if (is_int($value) && $value > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function monthLabels(): array
    {
        // ชื่อย่อเดือนภาษาไทย เพื่อให้กราฟบน dashboard ใช้ปฏิทินเดียวกับหน้า tickets/reports.
        return ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
    }

    private function buildMonthlySeries(array $rows, string $valueKey, bool $minutesToHours = false): array
    {
        $series = array_fill(0, 12, 0);

        foreach ($rows as $row) {
            $monthIndex = max(1, min(12, (int) ($row['month_no'] ?? 0))) - 1;
            $value = (float) ($row[$valueKey] ?? 0);
            $series[$monthIndex] = $minutesToHours ? round($value / 60, 1) : (int) round($value);
        }

        return $series;
    }

    private function buildDashboardChart(string $label, array $labels, array $data): array
    {
        return [
            'label' => $label,
            'labels' => array_values($labels),
            'data' => array_values($data),
            'has_data' => array_sum(array_map(static fn (mixed $value): float => (float) $value, $data)) > 0,
        ];
    }

    private function enumOptions(array $values): array
    {
        // ใช้เฉพาะกับ select ของระดับผลกระทบ/ความเร่งด่วน (severity: low/medium/high/critical).
        return array_map(static fn (string $value): array => [
            'value' => $value,
            'label' => severity_label_th($value),
        ], $values);
    }

    private function findReferenceById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    private function canManageComment(array $comment, array $viewer): bool
    {
        $viewerId = (int) ($viewer['id'] ?? 0);
        $role = (string) ($viewer['role'] ?? 'guest');

        return $viewerId > 0 && (
            (int) ($comment['user_id'] ?? 0) === $viewerId
            || is_manager_or_admin($role)
        );
    }


    private function submissionToken(string $token, bool $generateWhenMissing = true): string
    {
        $token = strtolower(trim($token));
        if (is_submission_token($token)) {
            return $token;
        }

        if (!$generateWhenMissing) {
            throw new DomainException('แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        return bin2hex(random_bytes(32));
    }

    private function statusTone(string $status): string
    {
        return ticket_status_tone($status);
    }

    private function approvalTone(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            default => 'default',
        };
    }

    private function priorityTone(string $priorityCode): string
    {
        return match ($priorityCode) {
            'URGENT' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            default => 'default',
        };
    }
}
