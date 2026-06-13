<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketRepository;

class SlaService
{
    public function __construct(
        private TicketRepository $tickets,
        private NotificationService $notifications,
    ) {
    }

    public function processOverdueBreaches(): array
    {
        $breachedAt = date('Y-m-d H:i:s');
        $processed = 0;
        $notifiedTickets = [];

        foreach ($this->tickets->getPendingOverdueSlaBreaches() as $track) {
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
            $this->notifications->notifySlaBreached($ticketId, $metricType);
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
