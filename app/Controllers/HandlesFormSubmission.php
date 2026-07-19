<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use DomainException;
use RuntimeException;

/**
 * ตัวจัดการกลางสำหรับ action แบบส่งฟอร์ม POST ที่ต้องล็อกอิน: บังคับ auth (ตรวจว่าล็อกอินแล้ว) (+ ตรวจ role ถ้าระบุ),
 * ตรวจ CSRF, รันการแก้ไขข้อมูล (mutation), แจ้งผลสำเร็จ/ผิดพลาดผ่าน flash, แล้วค่อย redirect. ส่ง
 * $oldInputOnError เพื่อเปิดใช้การเติมค่าเดิมกลับเข้าฟอร์ม (ล้างเมื่อสำเร็จ / คืนค่าเดิมเมื่อ error).
 * เป็นแหล่งเดียว (single source) สำหรับ AdminController / EmailQueueController / GuestRequestController / TicketsController.
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
            throw $__infra; // error ระดับ infra (โครงสร้างพื้นฐาน) → ตัวจัดการ error ส่วนกลางจะ log แล้วส่ง 500 แบบทั่วไป ไม่หลุด SQL ออกไป
        } catch (DomainException|RuntimeException $exception) {
            // DomainException คือการตรวจสอบข้อมูล (validation) ที่คาดไว้อยู่แล้ว (ไม่ต้อง log). ส่วน RuntimeException คือความผิดพลาด
            // ระดับปฏิบัติการ (OPERATIONAL) เช่น error จาก repo/filesystem/queue ที่แปลงเป็นข้อความอ่านง่ายให้ผู้ใช้ — ให้ log ต้นเหตุจริงไว้
            // ทีมงานจะได้ตรวจหาสาเหตุได้ ไม่ใช่เห็นแค่ข้อความ "ไม่สำเร็จ" ที่ผู้ใช้เห็น.
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
