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
            try {
                $this->notifications->notifySlaBreached($ticketId, $metricType);
            } catch (Throwable $exception) {
                log_caught_exception('sla.breach.notify', $exception, ['ticket_id' => $ticketId, 'metric' => $metricType]);
            }
            $notifiedTickets[] = [
                'ticket_id' => $ticketId,
                'ticket_no' => (string) ($track['ticket_no'] ?? ''),
                'metric_type' => $metricType,
            ];
        }

        return [
            'processed' => $processed,
            'items' => $notifiedTickets,
        ];
    }
}
