<?php

declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Repositories\TicketReadRepository;
use App\Services\ReportService;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// สุขภาพทรัพย์สิน is the other page closed_period_immutability_test lists as THIN, and it is the one that decides
// whether an organisation repairs a machine again or replaces it — so "how many times did this break" has to be
// countable by hand. One throwaway asset, one closed month:
//
//   A1 raised → resolved 2h later, 30m labour      → a real failure
//   A2 raised → resolved 4h later, 90m labour      → a real failure
//   A3 raised → cancelled                          → NOT a failure (the machine never actually broke)
//   A4 raised → rejected                           → NOT a failure
//   A5 dated in the FUTURE                         → NOT counted yet (a clock cannot report tomorrow's breakdown)
//
// By hand: เสีย 2 ครั้ง · เวลาซ่อมเฉลี่ย (2h + 4h)/2 = 3.0 · ชั่วโมงแรงงาน (30 + 90)/60 = 2.0 ·
// ครั้งล่าสุด = the later of the two real failures.

function ahk_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

test('assetHealth(known-answer): a machine\'s failure record equals the hand-computed value', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $svc = tvm_container()->get(ReportService::class);
    $pdo = ahk_pdo();
    $sfx = bin2hex(random_bytes(4));
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $assetRef = tvm_container()->get(AssetRepository::class)->getAssetFormReferenceData();
    $catId = (int) ($assetRef['categories'][0]['id'] ?? 0);
    $locId = (int) ($assetRef['locations'][0]['id'] ?? 0);
    $assetCode = "AHK-$sfx";
    $pdo->prepare(
        "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at)
         VALUES (?, ?, ?, ?, 'active', NOW(), NOW())"
    )->execute([$assetCode, "AhkAsset-$sfx", $catId, $locId]);
    $assetId = (int) $pdo->lastInsertId();

    $monthStart = (new DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('-3 months');
    $monthEnd = $monthStart->modify('last day of this month');
    $filters = [
        'from_date' => $monthStart->format('Y-m-d'),
        'to_date' => $monthEnd->modify('+2 months')->format('Y-m-d'), // window deliberately reaches past today
    ];
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $ids = [];

    /** @param string $outcome resolve|cancel|reject|future */
    $seed = static function (string $tag, string $outcome, int $hours, int $labour, int $dayOffset) use (
        $wf,
        $tickets,
        $pdo,
        $admin,
        $requester,
        $tech,
        $ref,
        $assetId,
        $monthStart,
        &$ids
    ): void {
        $id = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => "ahk $tag",
            'description' => 'x',
            'priority_id' => (int) $ref['priorities'][0]['id'],
            'ticket_category_id' => (int) $ref['categories'][0]['id'],
            'location_id' => (int) $ref['locations'][0]['id'],
            'asset_id' => $assetId,
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);
        $ids[$tag] = $id;

        if ($outcome === 'cancel') {
            $wf->cancelTicket($id, $requester, ['cancel_note' => 'แจ้งผิดเครื่อง']);
        } elseif ($outcome === 'reject') {
            $wf->rejectTicket($id, $admin, ['note' => 'ไม่อยู่ในขอบเขต']);
        } elseif ($outcome === 'resolve') {
            $wf->approveTicket($id, $admin, ['note' => '']);
            $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
            $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
            $wf->startAssignedWork($id, $tech, ['start_note' => '']);
            $wf->resolveAssignedWork($id, $tech, [
                'diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => (string) $labour,
            ]);
        } else { // future — an incident dated after today
            $wf->approveTicket($id, $admin, ['note' => '']);
        }

        // the asset link is set at creation; make sure it survived, then place the story in time
        $pdo->prepare('UPDATE tickets SET asset_id = ? WHERE id = ?')->execute([$assetId, $id]);

        $raised = $outcome === 'future'
            ? (new DateTimeImmutable('today'))->modify('+10 days +9 hours')
            : $monthStart->modify("+$dayOffset days +9 hours");
        $pdo->prepare('UPDATE tickets SET requested_at = ?, created_at = ? WHERE id = ?')
            ->execute([$raised->format('Y-m-d H:i:s'), $raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);
        $pdo->prepare('UPDATE ticket_sla_tracks SET created_at = ? WHERE ticket_id = ?')->execute([$raised->format('Y-m-d H:i:s'), $id]);

        if ($outcome === 'resolve') {
            $pdo->prepare("UPDATE ticket_activity_logs SET created_at = ? WHERE ticket_id = ? AND action = 'ticket_resolved'")
                ->execute([$raised->modify("+$hours hours")->format('Y-m-d H:i:s'), $id]);
        }
    };

    try {
        $seed('A1', 'resolve', 2, 30, 2);
        $seed('A2', 'resolve', 4, 90, 9);
        $seed('A3', 'cancel', 0, 0, 12);
        $seed('A4', 'reject', 0, 0, 15);
        $seed('A5', 'future', 0, 0, 0);

        $rows = $svc->getReportPageData($admin, $filters)['assetReliability'] ?? [];
        $row = null;
        foreach ($rows as $candidate) {
            if ((string) ($candidate['asset_code'] ?? '') === $assetCode) {
                $row = $candidate;
            }
        }
        assert_true($row !== null, 'the asset appears in the health table');

        assert_same(
            2,
            (int) $row['failure_count'],
            'only the two genuine breakdowns count — a cancelled report, a rejected one and a future-dated one are not failures'
        );
        assert_same('3.0', (string) $row['avg_resolution_hours_label'], 'average repair time = mean of 2h and 4h');
        assert_same('2.0', (string) $row['labor_hours_label'], 'recorded labour = 30m + 90m');
        assert_same(
            thai_datetime($monthStart->modify('+9 days +9 hours')->getTimestamp()),
            (string) $row['last_failure'],
            'the last failure is the later of the two REAL ones — not the report dated in the future'
        );
    } finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
        }
        $pdo->prepare('DELETE FROM assets WHERE id = ?')->execute([$assetId]);
    }
});
