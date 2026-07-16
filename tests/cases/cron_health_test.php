<?php

declare(strict_types=1);

use App\Repositories\SettingsRepository;
use App\Services\TicketService;

// error-review F4: a cron run that COMPLETES but leaves terminal failures (an email that exhausted its retries,
// an SLA breach whose alert never went out) must warn the admin — the heartbeat/exit(0) alone hid it. The cron
// records the failure count; getDashboardData surfaces it as cronFailures (read LIVE, not request-cached).

function crf_admin(): array
{
    return ['id' => 4, 'role' => 'admin'];
}

/** @param array<string, mixed>|null $orig */
function crf_restore(SettingsRepository $settings, string $key, ?array $orig): void
{
    if ($orig === null) {
        tvm_container()->get(PDO::class)->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);

        return;
    }
    $settings->upsert($key, $orig['setting_value'] ?? null, (string) ($orig['value_type'] ?? 'string'), (bool) ($orig['is_public'] ?? false), (int) ($orig['updated_by'] ?? 0));
}

test('F4 (dashboard): a recorded cron terminal-failure count surfaces as a dashboard warning', function (): void {
    $settings = tvm_container()->get(SettingsRepository::class);
    $svc = tvm_container()->get(TicketService::class);
    $origEmail = $settings->getByKey('cron_email_queue_last_failed');
    $origSla = $settings->getByKey('cron_sla_notify_last_failed');

    try {
        // a clean last run (0 failures) → no warning, even though the cron ran
        $settings->upsert('cron_email_queue_last_failed', '0', 'string', false, 0);
        $settings->upsert('cron_sla_notify_last_failed', '0', 'string', false, 0);
        assert_same([], $svc->getDashboardData(crf_admin())['cronFailures'], 'zero recorded failures → no cron-failure warning');

        // terminal failures recorded → surfaced with their counts
        $settings->upsert('cron_email_queue_last_failed', '3', 'string', false, 0);
        $settings->upsert('cron_sla_notify_last_failed', '2', 'string', false, 0);
        $failures = $svc->getDashboardData(crf_admin())['cronFailures'];
        assert_same(2, count($failures), 'both the email queue and SLA-notify failures surface');
        assert_contains_str('3', (string) ($failures[0]['detail'] ?? ''), 'the email terminal-failure count is shown');

        // never leaked to a non-admin
        assert_same([], $svc->getDashboardData(['id' => 1, 'role' => 'requester'])['cronFailures'], 'non-admins do not see cron internals');
    } finally {
        crf_restore($settings, 'cron_email_queue_last_failed', $origEmail);
        crf_restore($settings, 'cron_sla_notify_last_failed', $origSla);
    }
});
