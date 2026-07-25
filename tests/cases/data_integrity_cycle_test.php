<?php
declare(strict_types=1);

use App\Repositories\AdminRepository;
use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

function dic_pdo(): PDO
{
    return $GLOBALS['__container']->get(PDO::class);
}

test('integrity cycle: reopening with both SLA metrics disabled still stores the rating in the real lifecycle cycle', function (): void {
    $container = $GLOBALS['__container'];
    $adminRepository = $container->get(AdminRepository::class);
    $tickets = $container->get(TicketService::class);
    $workflow = $container->get(TicketWorkflowService::class);
    $reference = $container->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $suffix = strtoupper(bin2hex(random_bytes(4)));
    $priorityId = 0;
    $categoryId = 0;
    $ticketId = 0;

    try {
        $priorityId = $adminRepository->createPriority([
            'code' => 'NOSLA-' . $suffix,
            'name' => 'No SLA ' . $suffix,
            'level' => 200,
            'color' => 'slate',
            'response_time_minutes' => 0,
            'resolution_time_minutes' => 0,
            'sort_order' => 200,
            'is_active' => true,
        ]);
        $categoryId = $adminRepository->createTicketCategory([
            'code' => 'NOSLA-' . $suffix,
            'name' => 'No SLA Category ' . $suffix,
            'description' => '',
            'sort_order' => 200,
            'is_active' => true,
        ]);

        $requester = ['id' => 1, 'role' => 'requester'];
        $admin = ['id' => 4, 'role' => 'admin'];
        $technician = ['id' => 3, 'role' => 'technician'];
        $ticketId = $tickets->createTicket($requester, [
            'submission_token' => bin2hex(random_bytes(32)),
            'title' => 'No-SLA lifecycle cycle probe',
            'description' => 'This ticket is resolved and reopened three times.',
            'priority_id' => $priorityId,
            'ticket_category_id' => $categoryId,
            'location_id' => (int) $reference['locations'][0]['id'],
            'impact_level' => 'medium',
            'urgency_level' => 'medium',
        ], []);

        assert_same(
            0,
            (int) dic_pdo()->query("SELECT COUNT(*) FROM ticket_sla_tracks WHERE ticket_id = $ticketId")->fetchColumn(),
            'both SLA metrics are disabled, so there is deliberately no SLA row to carry the lifecycle cycle'
        );

        $workflow->approveTicket($ticketId, $admin, ['note' => '']);
        $workflow->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        for ($cycle = 1; $cycle <= 3; $cycle++) {
            $workflow->acceptAssignedWork($ticketId, $technician, ['accept_note' => '']);
            $workflow->resolveAssignedWork($ticketId, $technician, [
                'diagnosis_summary' => 'diagnosis cycle ' . $cycle,
                'resolution_summary' => 'resolution cycle ' . $cycle,
                'labor_minutes' => '1',
            ]);
            if ($cycle < 3) {
                $workflow->reopenTicket($ticketId, $requester, ['reopen_note' => 'reopen cycle ' . ($cycle + 1)]);
            }
        }
        $workflow->completeResolvedTicket($ticketId, $requester, [
            'score' => '5',
            'closure_note' => '',
            'feedback' => 'rated after cycle 3',
        ]);

        $ratingCycle = (int) dic_pdo()->query(
            "SELECT cycle FROM ticket_ratings WHERE ticket_id = $ticketId"
        )->fetchColumn();
        assert_same(3, $ratingCycle, 'two reopen events mean the completed/rated lifecycle is cycle 3');

        $violations = (int) dic_pdo()->query(
            "SELECT COUNT(*)
             FROM ticket_ratings r
             WHERE r.ticket_id = $ticketId
               AND r.cycle <> 1 + (
                   SELECT COUNT(*)
                   FROM ticket_activity_logs l
                   WHERE l.ticket_id = r.ticket_id AND l.action = 'ticket_reopened'
               )"
        )->fetchColumn();
        assert_same(0, $violations, 'post-hoc invariant: rating cycle always equals 1 + reopen count');
    } finally {
        if ($ticketId > 0) {
            dic_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
            dic_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
        }
        if ($priorityId > 0) {
            dic_pdo()->prepare('DELETE FROM priorities WHERE id = ?')->execute([$priorityId]);
        }
        if ($categoryId > 0) {
            dic_pdo()->prepare('DELETE FROM ticket_categories WHERE id = ?')->execute([$categoryId]);
        }
    }
});
