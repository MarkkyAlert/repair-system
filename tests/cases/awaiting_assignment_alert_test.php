<?php

declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;
use App\Services\TicketWorkflowService;

// Every step of the workflow calls somebody: a new ticket notifies the approvers, an assignment notifies the
// technician, a repair notifies the requester. One step called nobody — "approved, waiting for a technician".
// The requester was told their ticket was approved, and then nothing surfaced it to the people who assign: no
// notification, and no count on the dashboard, which alerts on work awaiting APPROVAL and on work already past
// its SLA but not on the gap between them. A ticket approved on Friday evening could sit unnoticed while its
// SLA ran down, and only reappear once it was already late.
//
// The owner chose a counter over a notification (2026-08-07): a notification is read once and disappears while
// the work is still sitting there, whereas a count stays up until the queue is actually empty.

/** @return array<int, string> the alert labels this viewer sees on the dashboard */
function aaa_alert_labels(array $viewer): array
{
    $data = tvm_container()->get(TicketService::class)->getDashboardData($viewer, []);

    return array_map(static fn (array $alert): string => (string) ($alert['label'] ?? ''), $data['urgentAlerts'] ?? []);
}

function aaa_awaiting_label(array $viewer): ?string
{
    foreach (aaa_alert_labels($viewer) as $label) {
        if (str_contains($label, 'รอมอบหมายช่าง')) {
            return $label;
        }
    }

    return null;
}

test('assignment queue: approved work with no technician is counted, and assigning it clears the count', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $pdo = tvm_container()->get(PDO::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];

    $countFor = static function (array $viewer): int {
        $label = aaa_awaiting_label($viewer);

        return $label === null ? 0 : (int) preg_replace('/\D/', '', $label);
    };
    $before = $countFor($admin);

    $id = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'awaiting assignment probe',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        // still awaiting approval — this is the OTHER queue, it must not be counted here
        assert_same($before, $countFor($admin), 'a ticket waiting for approval is not yet waiting for a technician');

        $wf->approveTicket($id, $admin, ['note' => '']);
        assert_same($before + 1, $countFor($admin), 'once approved with nobody assigned, it joins the assignment queue');

        $label = (string) aaa_awaiting_label($admin);
        assert_contains_str('รอมอบหมายช่าง', $label, 'the alert says plainly what is waiting');
        assert_contains_str((string) ($before + 1), $label, 'and carries the number waiting');

        // the alert has to lead somewhere that actually shows this work
        $alerts = tvm_container()->get(TicketService::class)->getDashboardData($admin, [])['urgentAlerts'] ?? [];
        $href = '';
        foreach ($alerts as $alert) {
            if (str_contains((string) ($alert['label'] ?? ''), 'รอมอบหมายช่าง')) {
                $href = (string) ($alert['href'] ?? '');
            }
        }
        assert_same('/tickets?status=approved', $href, 'it links to the list filtered to exactly this queue');

        // an icon name that is not in the set renders as a dashed "?" — visible, but broken-looking to a user
        $icon = '';
        foreach ($alerts as $alert) {
            if (str_contains((string) ($alert['label'] ?? ''), 'รอมอบหมายช่าง')) {
                $icon = (string) ($alert['icon'] ?? '');
            }
        }
        assert_true($icon !== '', 'the alert names an icon');
        assert_true(
            !str_contains(lucide($icon), 'lucide-missing'),
            "the alert's icon '{$icon}' exists in the icon set — an unknown name draws the missing-icon placeholder"
        );

        // assigning is what should clear it — the count tracks reality, it is not dismissed by reading it
        $wf->assignTechnician($id, $admin, ['technician_id' => 3, 'instructions' => '']);
        assert_same($before, $countFor($admin), 'assigning a technician takes the ticket back out of the queue');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    }
});

test('assignment queue: only the people who can assign are shown it', function (): void {
    $wf = tvm_container()->get(TicketWorkflowService::class);
    $tickets = tvm_container()->get(TicketService::class);
    $pdo = tvm_container()->get(PDO::class);
    $ref = tvm_container()->get(TicketReadRepository::class)->getCreateFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];
    $requester = ['id' => 1, 'role' => 'requester'];

    $id = $tickets->createTicket($requester, [
        'submission_token' => bin2hex(random_bytes(32)),
        'title' => 'awaiting assignment audience',
        'description' => 'x',
        'priority_id' => (int) $ref['priorities'][0]['id'],
        'ticket_category_id' => (int) $ref['categories'][0]['id'],
        'location_id' => (int) $ref['locations'][0]['id'],
        'impact_level' => 'medium',
        'urgency_level' => 'medium',
    ], []);

    try {
        $wf->approveTicket($id, $admin, ['note' => '']);

        assert_true(aaa_awaiting_label($admin) !== null, 'an admin assigns work, so an admin is shown the queue');
        assert_true(aaa_awaiting_label(['id' => 2, 'role' => 'manager']) !== null, 'so does a manager');

        // a requester raised the ticket and a technician waits to be given one; neither can assign, so telling
        // them a number they cannot act on is only noise
        assert_true(aaa_awaiting_label(['id' => 1, 'role' => 'requester']) === null, 'a requester is not shown a queue they cannot act on');
        assert_true(aaa_awaiting_label(['id' => 3, 'role' => 'technician']) === null, 'nor is a technician');

        // the alerts they DO get must be untouched by this
        assert_true(aaa_alert_labels(['id' => 1, 'role' => 'requester']) !== [], 'the requester still sees their own existing alerts');
    } finally {
        $pdo->prepare('DELETE FROM tickets WHERE id = ?')->execute([$id]);
    }
});
