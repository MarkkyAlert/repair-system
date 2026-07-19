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

    /**
     * ข้อมูลกระดิ่งแจ้งเตือนแบบสด (GET, ต้องล็อกอิน) สำหรับ poll — คืน JSON จาก NotificationService::getFeedData (ไม่เขียน DB).
     */
    public function feed(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        Response::json($this->notifications->getFeedData($viewer));
    }

    // ไม่ใช้ handleUpdate(): การ mark ว่าอ่านแล้วเงียบ ๆ พอสำเร็จ (ไม่มี flash toast — เพราะมันเป็นผลพลอยได้
    // จากการเปิดการแจ้งเตือนอยู่แล้ว) ส่วน handleUpdate จะ flash ข้อความสำเร็จเสมอ.
    /**
     * ทำเครื่องหมายว่าอ่านการแจ้งเตือนหนึ่งรายการ (POST, ต้องล็อกอิน + CSRF) ผ่าน NotificationService::markAsRead.
     * ผลข้างเคียง: อัปเดตแถวแจ้งเตือนเป็นอ่านแล้ว (เฉพาะของเจ้าของ); ไม่มี flash toast เมื่อสำเร็จ (เงียบ ๆ).
     * redirect ไป return_to ที่ sanitize แล้ว (ค่าเริ่มต้น /notifications).
     */
    public function read(string $notificationId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markAsRead((int) $notificationId, $viewer);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($returnTo);
    }

    /**
     * ทำเครื่องหมายว่าอ่านการแจ้งเตือนทั้งหมดของ ticket หนึ่งใบ (POST, ต้องล็อกอิน + CSRF) ผ่าน NotificationService::markTicketAsRead.
     * ผลข้างเคียง: อัปเดตแถวแจ้งเตือนที่ผูกกับ ticket นั้นเป็นอ่านแล้ว (เฉพาะของเจ้าของ); เงียบ ๆ ไม่มี flash toast.
     * redirect ไป return_to ที่ sanitize แล้ว (ค่าเริ่มต้น /notifications).
     */
    public function readTicket(string $ticketId): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/notifications'));

        try {
            csrf_validate();
            $this->notifications->markTicketAsRead((int) $ticketId, $viewer);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
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
