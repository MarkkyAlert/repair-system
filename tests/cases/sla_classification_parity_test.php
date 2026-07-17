<?php

declare(strict_types=1);

use App\Services\ReportService;
use App\Services\TicketService;

// dup-review F1: the SLA "met / breached / unavailable" decision lived in two near-identical private classifiers
// (ticket detail vs reports) and had DRIFTED — the report guarded an achievement dated BEFORE the ticket was
// requested (corrupt seed/import data) as 'unavailable', but the ticket-detail classifier scored it as 'met'.
// Both read surfaces must judge the same ticket identically. Drives both private classifiers via reflection.

function sla_ticket_status(string $due, string $achieved, string $requested): string
{
    $svc = tvm_container()->get(TicketService::class);
    $m = new ReflectionMethod($svc, 'buildSlaMetricState');
    $m->setAccessible(true);

    return (string) ($m->invoke($svc, 'Resolution SLA', $due, $achieved, $requested)['status'] ?? '');
}

function sla_report_status(string $due, string $achieved, string $requested): string
{
    $svc = tvm_container()->get(ReportService::class);
    $m = new ReflectionMethod($svc, 'buildSlaMetricState');
    $m->setAccessible(true);

    return (string) ($m->invoke($svc, $due, $achieved, $requested)['status'] ?? '');
}

test('sla-parity(F1): an achieved-before-requested timestamp is "unavailable" in BOTH ticket detail and reports', function (): void {
    $requested = '2026-01-10 10:00:00';
    $due = '2026-01-10 14:00:00';
    $corruptAchieved = '2026-01-05 09:00:00'; // dated before the ticket was even requested → impossible data

    $ticket = sla_ticket_status($due, $corruptAchieved, $requested);
    $report = sla_report_status($due, $corruptAchieved, $requested);
    assert_same('unavailable', $ticket, 'ticket detail treats the corrupt timestamp as unavailable, not a false "met"');
    assert_same('unavailable', $report, 'reports treat it as unavailable');
    assert_same($report, $ticket, 'the two read surfaces agree on the corrupt case');
});

test('sla-parity(F1): normal met/breached classification also agrees across both surfaces', function (): void {
    $requested = '2026-01-10 10:00:00';
    $due = '2026-01-10 14:00:00';

    // on time: achieved after the request, at/before the due time
    assert_same('met', sla_ticket_status($due, '2026-01-10 12:00:00', $requested), 'ticket: on-time → met');
    assert_same('met', sla_report_status($due, '2026-01-10 12:00:00', $requested), 'report: on-time → met');

    // late: achieved after the due time
    assert_same('breached', sla_ticket_status($due, '2026-01-10 16:00:00', $requested), 'ticket: late → breached');
    assert_same('breached', sla_report_status($due, '2026-01-10 16:00:00', $requested), 'report: late → breached');
});
