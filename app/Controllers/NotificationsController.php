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
    use HandlesFormSubmission;

    public function __construct(private NotificationService $notifications)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $data = $this->notifications->getNotificationPageData($viewer, request()?->query ?? []);

        Response::view('notifications/index', [
            'title' => 'การแจ้งเตือน',
            'pageHeading' => 'การแจ้งเตือน',
            'currentUser' => $viewer,
            'notifications' => $data['notifications'],
            'groups' => $data['groups'],
            'unreadCount' => $data['unreadCount'],
            'unreadThreadCount' => $data['unreadThreadCount'],
            'actionCount' => $data['actionCount'],
            'threadCount' => $data['threadCount'],
            'selectedFilter' => $data['selectedFilter'],
            'filterOptions' => $data['filterOptions'],
            'pagination' => $data['pagination'],
        ]);
    }

    public function feed(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        Response::json($this->notifications->getFeedData($viewer));
    }

    // ไม่ใช้ handleUpdate(): การทำเครื่องหมายว่าอ่านแล้วจะเงียบเมื่อสำเร็จ (ไม่มี flash toast — เพราะเป็นผลพลอยได้
    // ที่เกิดขึ้นจากการเปิดการแจ้งเตือน) ขณะที่ handleUpdate จะ flash ข้อความสำเร็จเสมอ.
    public function read(string $notificationId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markAsRead((int) $notificationId, $viewer);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($returnTo);
    }

    public function readTicket(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markTicketAsRead((int) $ticketId, $viewer);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($returnTo);
    }

    public function readAll(): void
    {
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));
        $this->handleUpdate(
            fn (array $viewer) => $this->notifications->markAllAsRead($viewer),
            'อัปเดตสถานะ notifications เป็นอ่านแล้วทั้งหมดเรียบร้อย',
            $returnTo
        );
    }
}
