<?php
declare(strict_types=1);

use App\Services\AssetService;
use App\Services\TicketService;

/** DI container booted once by run.php (services need real repo/PDO deps to construct). */
function tvm_container(): \App\Core\Container
{
    return $GLOBALS['__container'];
}

// ── TicketService filter-chip / urgent-alert view-model (moved out of the view this session) ──
test('buildTicketFilterChips: status+priority+sla → 3 chips, canonical labels, dismiss drops own key', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    $chips = call_private($svc, 'buildTicketFilterChips', [
        ['q' => '', 'status' => 'in_progress', 'priority' => 'HIGH', 'technician_id' => 0, 'sla' => 'overdue'],
        [],
    ]);
    assert_count(3, $chips);
    assert_same('สถานะ: กำลังดำเนินการ', $chips[0]['label']);
    assert_same('ความสำคัญ: สูง', $chips[1]['label']);
    assert_same('SLA: เกินกำหนด', $chips[2]['label']);
    assert_contains_str('/tickets', $chips[0]['dismiss']);
    assert_false(str_contains($chips[0]['dismiss'], 'status='), 'status chip dismiss removes status');
});

test('buildTicketFilterChips: technician label resolved from list', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    $chips = call_private($svc, 'buildTicketFilterChips', [
        ['q' => '', 'status' => '', 'priority' => '', 'technician_id' => 3, 'sla' => ''],
        [['id' => 3, 'label' => 'ช่างเอก']],
    ]);
    assert_count(1, $chips);
    assert_same('ช่าง: ช่างเอก', $chips[0]['label']);
});

test('buildTicketUrgentAlerts: only present metrics, correct tone/count', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    $alerts = call_private($svc, 'buildTicketUrgentAlerts', [['overdue' => 5, 'pendingApproval' => 0]]);
    assert_count(1, $alerts);
    assert_same('danger', $alerts[0]['tone']);
    assert_contains_str('5 รายการ', $alerts[0]['label']);
    assert_count(0, call_private($svc, 'buildTicketUrgentAlerts', [['overdue' => 0, 'pendingApproval' => 0]]));
});

// ── Dashboard view-model (moved out of dashboard/index.php this session) ──
test('summarizeChart: total / top / avg-of-nonzero with unit', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    $s = call_private($svc, 'summarizeChart', [['labels' => ['ม.ค.', 'ก.พ.', 'มี.ค.'], 'data' => [2, 8, 0]], 'รายการ']);
    assert_same('10 รายการ', $s['total']);
    assert_same('ก.พ. 8 รายการ', $s['top']);
    assert_same('5 รายการ', $s['avg']);
});

test('buildDashboardPrimaryCta: role-based href', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    assert_same('/dashboard?preset=pending_approval', call_private($svc, 'buildDashboardPrimaryCta', ['manager'])['href']);
    assert_same('/tickets', call_private($svc, 'buildDashboardPrimaryCta', ['technician'])['href']);
    assert_same('/tickets/create', call_private($svc, 'buildDashboardPrimaryCta', ['requester'])['href']);
});

test('buildDashboardCronHealth: empty for non-admin', function (): void {
    $svc = tvm_container()->get(TicketService::class);
    assert_count(0, call_private($svc, 'buildDashboardCronHealth', ['technician']));
});

// ── AssetService filter-chip view-model ──
test('buildAssetActiveFilterChips: q + status chips, canonical status label, dismiss url', function (): void {
    $svc = tvm_container()->get(AssetService::class);
    $chips = call_private($svc, 'buildAssetActiveFilterChips', [
        ['q' => 'printer', 'category_id' => 0, 'location_id' => 0, 'status' => 'active'],
        ['categories' => [], 'locations' => []],
    ]);
    assert_count(2, $chips);
    assert_same('คำค้น: printer', $chips[0]['label']);
    assert_same('สถานะ: ใช้งานอยู่', $chips[1]['label']);
    assert_contains_str('/asset-registry', $chips[1]['dismiss']);
});
