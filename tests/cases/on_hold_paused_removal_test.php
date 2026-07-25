<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// state-machine audit (2026-07-25, owner decision B): 'on_hold' (tickets.status) and 'paused' (work_orders.status)
// were declared in the enums + labelled, but nothing in the workflow could ever REACH them — no transition wrote
// on_hold/paused. They were a floating label, not a feature. Owner chose to remove them cleanly rather than build a
// pause feature nobody asked for (a real pause needs its own requirement, e.g. does SLA stop counting while paused).
// These tests lock the removal: the retired values must be absent from every source of truth, and no code may
// reintroduce them — while the surrounding transitions (assigned → in_progress → resolved → completed) keep working.

/** Parse the tickets.status enum values out of schema.sql (anchored on its DEFAULT to skip assets.status). */
function ohp_ticket_status_enum(): array
{
    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    if (!preg_match("/\n\s*status ENUM\(([^)]*)\)\s*NOT NULL DEFAULT 'pending_approval'/", $schema, $m)) {
        throw new RuntimeException('could not locate the tickets.status ENUM in schema.sql');
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $vals);

    return $vals[1];
}

test('on_hold/paused(lock): the retired values are gone from every canonical source of truth', function (): void {
    // 1) the tickets.status single source (PHP) and the schema enum agree, and neither carries on_hold
    $phpValues = ticket_status_values();
    assert_count(10, $phpValues, 'the canonical ticket status list contains only reachable/currently supported states');
    assert_false(in_array('on_hold', $phpValues, true), 'on_hold is not a ticket status the app offers');

    $ticketEnum = ohp_ticket_status_enum();
    assert_false(in_array('on_hold', $ticketEnum, true), 'on_hold is not in the tickets.status column');
    assert_same(
        $phpValues,
        $ticketEnum,
        'the PHP status list and the schema enum stay in lock-step (order + membership)'
    );

    // 2) the work_orders.status enum dropped paused. ohp_schema_enum('status') matches the FIRST status ENUM
    //    (tickets); the work_orders one is the status enum that has assigned+completed but not the ticket-only
    //    'pending_approval', so pick it out explicitly.
    $schema = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
    preg_match_all("/status ENUM\(([^)]*)\)/", $schema, $all);
    $woDecl = '';
    foreach ($all[1] as $decl) {
        if (str_contains($decl, 'assigned') && str_contains($decl, 'completed') && !str_contains($decl, 'pending_approval')) {
            $woDecl = $decl;
        }
    }
    assert_true($woDecl !== '', 'located the work_orders.status enum');
    assert_false(str_contains($woDecl, 'paused'), 'paused is not in the work_orders.status column');

    // 3) the labels no longer resolve the retired values — an unknown value falls through to the humanized fallback,
    //    which proves there is no Thai mapping left (a live mapping would return 'พักงานชั่วคราว', not 'Paused')
    assert_same('Paused', work_order_status_label_th('paused'), 'no Thai label maps to the retired paused status');
    assert_same('On Hold', ticket_status_label_th('on_hold'), 'no Thai label maps to the retired on_hold status');
});

test('on_hold/paused(source-lock): no app/database source reintroduces the retired states', function (): void {
    // scan the LIVE shipped source: a re-added 'on_hold'/'paused' literal (enum, label, filter, demo row) turns this
    // red. database/upgrades/ is excluded on purpose — one-off migration scripts must name the retired values to
    // convert/drop them (that IS their job), so they reference old column states by design.
    $roots = [BASE_PATH . '/app', BASE_PATH . '/database', BASE_PATH . '/bin'];
    $offenders = [];
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/upgrades/')) {
                continue; // historical migrations legitimately reference retired states
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['php', 'sql', 'css', 'js'], true)) {
                continue;
            }
            $body = (string) file_get_contents($path);
            if (preg_match('/\bon_hold\b/', $body) || preg_match('/\bpaused\b/', $body)) {
                $offenders[] = str_replace(BASE_PATH . '/', '', $path);
            }
        }
    }
    assert_same([], $offenders, 'no shipped source file references on_hold/paused: ' . implode(', ', $offenders));
});

test('on_hold/paused(power-proof): the surrounding transitions still flow assigned → in_progress → resolved → completed', function (): void {
    // Removing the dead states must not disturb the live path that used to sit on either side of on_hold. Drive the
    // real workflow end-to-end and assert the work order walks assigned → in_progress → completed with no gap.
    $tickets = tvm_container()->get(TicketService::class);
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $pdo = tvm_container()->get(PDO::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];
    $req = ['id' => 1, 'role' => 'requester'];
    $tech = ['id' => 3, 'role' => 'technician'];

    $id = $tickets->createTicket($req, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'on_hold-removal-happy-path',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        $wf->approveTicket($id, $admin, ['note' => '']);
        $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        assert_same('assigned', (string) $pdo->query("SELECT status FROM work_orders WHERE ticket_id = $id")->fetchColumn(), 'work order opens assigned');

        $wf->acceptAssignedWork($id, $tech, ['accept_note' => '']);
        $wf->startAssignedWork($id, $tech, ['start_note' => '']);
        assert_same('in_progress', (string) $pdo->query("SELECT status FROM tickets WHERE id = $id")->fetchColumn(), 'ticket reaches in_progress (the state on_hold used to branch off)');
        assert_same('in_progress', (string) $pdo->query("SELECT status FROM work_orders WHERE ticket_id = $id")->fetchColumn(), 'work order reaches in_progress — never paused');

        $wf->resolveAssignedWork($id, $tech, ['diagnosis_summary' => 'd', 'resolution_summary' => 'r', 'labor_minutes' => '10']);
        assert_same('resolved', (string) $pdo->query("SELECT status FROM tickets WHERE id = $id")->fetchColumn(), 'ticket resolves');

        $wf->completeResolvedTicket($id, $req, ['score' => 5, 'feedback' => 'ok']);
        assert_same('completed', (string) $pdo->query("SELECT status FROM tickets WHERE id = $id")->fetchColumn(), 'ticket completes — the full path is intact after the removal');
        assert_same('completed', (string) $pdo->query("SELECT status FROM work_orders WHERE ticket_id = $id")->fetchColumn(), 'work order completes');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    }
});
