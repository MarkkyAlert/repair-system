<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\NotificationService;
use DomainException;
use RuntimeException;

class NotificationsController
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $data = $this->notifications->getNotificationPageData($viewer, request()?->query ?? []);

        Response::view('notifications/index', [
            'title' => 'Notifications',
            'pageHeading' => 'การแจ้งเตือน',
            'currentUser' => $viewer,
            'notifications' => $data['notifications'],
            'groups' => $data['groups'],
            'unreadCount' => $data['unreadCount'],
            'selectedFilter' => $data['selectedFilter'],
            'filterOptions' => $data['filterOptions'],
        ]);
    }

    public function feed(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        Response::json($this->notifications->getFeedData($viewer));
    }

    public function read(string $notificationId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markAsRead((int) $notificationId, $viewer);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($returnTo);
    }

    public function readAll(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markAllAsRead($viewer);
            flash('success', 'อัปเดตสถานะ notifications เป็นอ่านแล้วทั้งหมดเรียบร้อย');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($returnTo);
    }
}
