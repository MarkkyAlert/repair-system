<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Response;
use App\Core\Session;

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return Csrf::field();
}

function csrf_validate(): void
{
    // อ่านเป็น mixed ก่อน: body ที่จงใจปลอมอย่าง `_csrf[]=x` จะทำให้ $_POST['_csrf'] เป็น array ซึ่งไปชน
    // type declaration ของ Csrf::validate(?string) แล้วโยน TypeError ที่ไม่มีใครดัก → HTTP 500 เลยแปลงค่าที่
    // ไม่ใช่ string ให้เป็น null มันจะได้ถูกปฏิเสธเป็น DomainException ตามที่คาดไว้ผ่าน flow ปกติ
    $token = $_POST['_csrf'] ?? null;
    Csrf::validate(is_string($token) ? $token : null);
}

/**
 * จำกัด action ของ controller ให้เฉพาะ role ที่กำหนด เรียกหลัง AuthMiddleware::handle()
 * รวมการเช็ค "role ไหนไม่ได้รับอนุญาต → 403" ไว้ที่เดียว จะได้ไม่ลืมเช็คไปเงียบ ๆ
 */
function require_role(array $viewer, array $roles, string $message): void
{
    if (!in_array((string) ($viewer['role'] ?? \App\Support\Role::GUEST), $roles, true)) {
        Response::abort(403, $message);
    }
}

/**
 * เช็คว่าผู้ใช้ที่กำลังดูเป็น admin ไหม ถ้าไม่ใช่ก็โยน DomainException เป็นคู่หูของ require_role()
 * ฝั่ง service: ใช้ใน method ของ service (ผู้เรียกดัก DomainException
 * → flash error) ต่างจาก require_role() ที่ abort 403 เด็ดขาดตั้งแต่ทางเข้า controller
 */
function assert_admin(array $viewer): void
{
    if ((string) ($viewer['role'] ?? \App\Support\Role::GUEST) !== \App\Support\Role::ADMIN) {
        throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
    }
}

/** คืน true เมื่อ role มีสิทธิ์ระดับสูง คือ manager หรือ admin ใช้เป็นเงื่อนไขเช็คในหลาย ๆ ที่ */
function is_manager_or_admin(string $role): bool
{
    return in_array($role, [\App\Support\Role::MANAGER, \App\Support\Role::ADMIN], true);
}

function flash(string $key, mixed $value): void
{
    Session::flash($key, $value);
}

function flash_message(string $key, mixed $default = null): mixed
{
    return Session::pullFlash($key, $default);
}

function has_flash(string $key): bool
{
    return Session::hasFlash($key);
}

function old(string $key, mixed $default = null): mixed
{
    $old = Session::get('_old_input', []);

    return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
}

function pull_old_input(): array
{
    $old = Session::get('_old_input', []);
    clear_old_input();

    return is_array($old) ? $old : [];
}

function with_old_input(array $input): void
{
    Session::put('_old_input', $input);
}

function clear_old_input(): void
{
    Session::forget('_old_input');
}
