<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\TicketService;

class DashboardController
{
    public function __construct(private TicketService $tickets)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $dashboard = $this->tickets->getDashboardData($viewer, request()?->query ?? []);

        Response::view('dashboard/index', [
            'title' => 'แดชบอร์ด',
            'pageHeading' => 'ภาพรวมการปฏิบัติงาน',
            'currentUser' => $viewer,
            'metrics' => $dashboard['metrics'],
            'recentTickets' => $dashboard['recentTickets'],
            'filters' => $dashboard['filters'],
            'charts' => $dashboard['charts'],
            'highlights' => $dashboard['highlights'],
            'csat' => $dashboard['csat'],
            'primaryCta' => $dashboard['primaryCta'],
            'cronHealth' => $dashboard['cronHealth'],
            'cronFailures' => $dashboard['cronFailures'],
            'setupChecklist' => $dashboard['setupChecklist'],
            'urgentAlerts' => $dashboard['urgentAlerts'],
            'chartSummaries' => $dashboard['chartSummaries'],
        ]);
    }
}
