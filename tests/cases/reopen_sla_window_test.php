<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// bug-hunt MED#4: calculateReopenDueAt kept the SLA window "the same as first allotted" by computing
// (current due_at − requested_at). But reopen OVERWRITES tickets.due_at while requested_at stays original, so
// from the 2nd reopen on the window ballooned by the ticket's age at the first reopen — each reopen handed the
// technician a longer and longer deadline. The window is now read from the untouched first sla-cycle track
// (firstSlaWindowMinutes), so it stays constant across any number of reopens. Driven through the REAL services;
// the ticket is backdated 3h so the balloon (age-at-first-reopen) is measurable in a sub-second test.

function rsw_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** window (minutes) of the first ('ASC') or latest ('DESC') resolution SLA cycle */
function rsw_resolution_window(int $ticketId, string $order): int
{
    $stmt = rsw_pdo()->prepare(
        "SELECT TIMESTAMPDIFF(MINUTE, created_at, target_at) FROM ticket_sla_tracks
         WHERE ticket_id = ? AND metric_type = 'resolution' ORDER BY cycle $order LIMIT 1"
    );
    $stmt->execute([$ticketId]);

    return (int) $stmt->fetchColumn();
}

/** how many SLA track rows exist for a metric on a ticket */
function rsw_track_count(int $ticketId, string $metric): int
{
    $stmt = rsw_pdo()->prepare('SELECT COUNT(*) FROM ticket_sla_tracks WHERE ticket_id = ? AND metric_type = ?');
    $stmt->execute([$ticketId, $metric]);

    return (int) $stmt->fetchColumn();
}

test('reopen SLA window: the resolution deadline window stays constant across repeated reopens (does NOT balloon) — bug-hunt MED#4', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    $ticketId = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'reopen-sla-window ' . bin2hex(random_bytes(3)),
        'description' => 'MED#4 probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        $originalWindow = rsw_resolution_window($ticketId, 'ASC'); // M — the resolution window allotted at create
        assert_true($originalWindow > 0, 'the priority carries a positive resolution SLA window');

        $wf->approveTicket($ticketId, $admin, ['note' => '']);
        $wf->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd1', 'resolution_summary' => 'r1', 'labor_minutes' => '10']);

        // Pretend this ticket was genuinely created 3h ago: shift the ticket AND its first cycle back uniformly so
        // the window is preserved but the age-at-first-reopen is now 180 min (the balloon the old formula added).
        rsw_pdo()->prepare('UPDATE tickets SET requested_at = DATE_SUB(requested_at, INTERVAL 180 MINUTE), response_due_at = DATE_SUB(response_due_at, INTERVAL 180 MINUTE), resolution_due_at = DATE_SUB(resolution_due_at, INTERVAL 180 MINUTE) WHERE id = ?')->execute([$ticketId]);
        rsw_pdo()->prepare('UPDATE ticket_sla_tracks SET created_at = DATE_SUB(created_at, INTERVAL 180 MINUTE), target_at = DATE_SUB(target_at, INTERVAL 180 MINUTE) WHERE ticket_id = ? AND cycle = 1')->execute([$ticketId]);

        // first reopen (cycle 2) — old formula still correct here (due_at not yet mutated relative to requested_at)
        $wf->reopenTicket($ticketId, $requester, ['reopen_note' => 'ยังไม่หาย รอบ 1']);
        $wf->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd2', 'resolution_summary' => 'r2', 'labor_minutes' => '10']);

        // second reopen (cycle 3) — the window must STILL equal M, not M + (age at first reopen)
        $wf->reopenTicket($ticketId, $requester, ['reopen_note' => 'ยังไม่หาย รอบ 2']);

        $latestWindow = rsw_resolution_window($ticketId, 'DESC');
        assert_true(abs($latestWindow - $originalWindow) <= 1, "the reopen resolution window stays ~{$originalWindow} min across reopens (ballooned to {$latestWindow} without the fix)");
    } finally {
        rsw_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        rsw_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]); // cascades work_orders / sla_tracks / logs
    }
});

// bug-hunt R4-A: a category with an SLA metric set to 0 min = "SLA disabled" — createTicket honors this (no track,
// due=null; see the 0=disable comment in TicketService). But reopen recomputed the due for EVERY metric and
// appended a track unconditionally: a disabled metric fell back to "due = reopen time", so reopen fabricated a
// fresh SLA track that the very next cron run flags as breached — a phantom "SLA เกินกำหนด" for a metric the admin
// turned off. A disabled metric must stay disabled (no track, null due) across reopen, mirroring create.
test('reopen SLA: a disabled (0-min / no-track) metric is NOT resurrected as an instant breach on reopen — R4-A', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $tech = ['id' => 3, 'role' => 'technician'];
    $requester = ['id' => 1, 'role' => 'requester'];
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();

    $ticketId = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'reopen-sla-disabled ' . bin2hex(random_bytes(3)),
        'description' => 'R4-A probe',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        $wf->approveTicket($ticketId, $admin, ['note' => '']);
        $wf->assignTechnician($ticketId, $admin, ['technician_id' => 3, 'instructions' => '']);
        $wf->acceptAssignedWork($ticketId, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($ticketId, $tech, ['start_note' => '']);
        $wf->resolveAssignedWork($ticketId, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);

        // Put the ticket into the exact state a 0-min RESPONSE category yields: no response track + response_due_at NULL.
        // (createTicket's 0=disable skip is covered elsewhere; here we isolate the reopen behavior.) Resolution stays enabled.
        rsw_pdo()->prepare("DELETE FROM ticket_sla_tracks WHERE ticket_id = ? AND metric_type = 'response'")->execute([$ticketId]);
        rsw_pdo()->prepare('UPDATE tickets SET response_due_at = NULL WHERE id = ?')->execute([$ticketId]);
        assert_same(0, rsw_track_count($ticketId, 'response'), 'precondition: response SLA is disabled (no track)');

        $wf->reopenTicket($ticketId, $requester, ['reopen_note' => 'ยังไม่หาย ขอตรวจซ้ำ']);

        // the disabled response metric must NOT be resurrected: still zero tracks, still no due
        assert_same(0, rsw_track_count($ticketId, 'response'), 'reopen must NOT fabricate a response SLA track for a disabled metric (would be an instant phantom breach)');
        $responseDue = rsw_pdo()->query("SELECT response_due_at FROM tickets WHERE id = $ticketId")->fetchColumn();
        assert_true($responseDue === null, 'response_due_at stays NULL after reopen for a disabled metric (not set to the reopen time)');

        // sanity: the still-ENABLED resolution metric DOES get a fresh reopen cycle — reopen keeps tracking real SLAs
        assert_true(rsw_track_count($ticketId, 'resolution') >= 2, 'the enabled resolution metric still gets a new SLA cycle on reopen');
    } finally {
        rsw_pdo()->prepare("DELETE FROM notifications WHERE related_type = 'ticket' AND related_id = ?")->execute([$ticketId]);
        rsw_pdo()->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticketId]);
    }
});
