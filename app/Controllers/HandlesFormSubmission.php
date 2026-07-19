<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use DomainException;
use RuntimeException;

/**
 * ตัวจัดการกลางสำหรับ action แบบส่งฟอร์ม POST ที่ต้องล็อกอิน: เช็ก auth ว่าล็อกอินแล้ว (+ เช็ก role ถ้าระบุ),
 * เช็ก CSRF, รัน mutation, แจ้งผลสำเร็จ/พังผ่าน flash, แล้วค่อย redirect. ส่ง
 * $oldInputOnError เข้ามาเมื่ออยากเติมค่าเดิมกลับเข้าฟอร์ม (ล้างเมื่อสำเร็จ / คืนค่าเดิมเมื่อ error).
 * ใช้เป็นที่เดียวสำหรับ AdminController / EmailQueueController / GuestRequestController / TicketsController.
 */
trait HandlesFormSubmission
{
    protected function handleUpdate(
        callable $callback,
        string $successMessage = 'บันทึกข้อมูลเรียบร้อยแล้ว',
        string $redirectTo = '/admin',
        ?array $requireRoles = null,
        string $roleMessage = '',
        ?array $oldInputOnError = null
    ): void {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if ($requireRoles !== null) {
            require_role($viewer, $requireRoles, $roleMessage);
        }

        try {
            csrf_validate();
            if ($oldInputOnError !== null) {
                clear_old_input();
            }
            $callback($viewer);
            flash('success', $successMessage);
        } catch (\PDOException $__infra) {
            throw $__infra; // error ระดับ infra ปล่อยให้ตัวจัดการ error ส่วนกลาง log แล้วส่ง 500 กลาง ๆ ไม่ให้ SQL หลุดออกไป
        } catch (DomainException|RuntimeException $exception) {
            // DomainException คือ validation ที่คาดไว้อยู่แล้ว ไม่ต้อง log. ส่วน RuntimeException เป็นปัญหา
            // ระดับปฏิบัติการ เช่น error จาก repo/filesystem/queue ที่แปลงเป็นข้อความอ่านง่ายให้ผู้ใช้ — log ต้นเหตุจริงไว้
            // ทีมจะได้ไล่หาสาเหตุได้ ไม่ใช่เห็นแค่ข้อความ "ไม่สำเร็จ" ที่ผู้ใช้เห็น.
            if ($exception instanceof RuntimeException) {
                log_caught_exception('form.operational', $exception, ['path' => (string) (request()?->path ?? '')]);
            }
            if ($oldInputOnError !== null) {
                with_old_input($oldInputOnError);
            }
            flash('error', $exception->getMessage());
        }

        Response::redirect($redirectTo);
    }
}
