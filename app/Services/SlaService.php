<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use Throwable;

class SlaService
{
    public function __construct(
        private TicketRepository $tickets,
        private TicketReadRepository $reads,
        private NotificationService $notifications,
    ) {
    }

    public function processOverdueBreaches(): array
    {
        $breachedAt = date('Y-m-d H:i:s');
        $processed = 0;
        $notifyFailed = 0;
        $notifiedTickets = [];

        foreach ($this->reads->getPendingOverdueSlaBreaches() as $track) {
            $slaTrackId = (int) ($track['id'] ?? 0);
            $ticketId = (int) ($track['ticket_id'] ?? 0);
            $metricType = (string) ($track['metric_type'] ?? 'resolution');

            if ($slaTrackId <= 0 || $ticketId <= 0) {
                continue;
            }

            $marked = $this->tickets->markSlaBreachedById($slaTrackId, $breachedAt);
            if (!$marked) {
                continue;
            }

            $processed++;
            // Best-effort notify: the breach is already committed (single-statement UPDATE), and this cron
            // processes a BATCH. A failed notification for one ticket must not abort the loop — otherwise the
            // remaining breaches go unmarked/unnotified this run and this ticket's own notify is never retried
            // (next run it is no longer "pending"). Log and carry on.
            // notifySlaBreached returns whether the in-app alert was actually written (the dispatch swallows +
            // logs its own failure), so a swallowed failure is now counted — not just an outright throw.
            $notified = true;
            try {
                $notified = $this->notifications->notifySlaBreached($ticketId, $metricType);
            } catch (Throwable $exception) {
                log_caught_exception('sla.breach.notify', $exception, ['ticket_id' => $ticketId, 'metric' => $metricType]);
                $notified = false;
            }
            if (!$notified) {
                $notifyFailed++;
            }
            $notifiedTickets[] = [
                'ticket_id' => $ticketId,
                'ticket_no' => (string) ($track['ticket_no'] ?? ''),
                'metric_type' => $metricType,
                'notified' => $notified,
            ];
        }

        // Separate "marked as breached" (committed) from "actually notified": a notify failure must NOT be
        // reported as a successful notification — the breach stands but its alert never went out and won't be
        // retried (next run it is no longer pending). The cron surfaces notify_failed.
        return [
            'processed' => $processed,
            'notified' => $processed - $notifyFailed,
            'notify_failed' => $notifyFailed,
            'items' => $notifiedTickets,
        ];
    }
}
