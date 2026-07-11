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
    Csrf::validate($_POST['_csrf'] ?? null);
}

/**
 * Guard a controller action to the given role(s). Call after AuthMiddleware::handle().
 * Centralises the "if role not allowed → 403" check so it can't be silently forgotten.
 */
function require_role(array $viewer, array $roles, string $message): void
{
    if (!in_array((string) ($viewer['role'] ?? \App\Support\Role::GUEST), $roles, true)) {
        Response::abort(403, $message);
    }
}

/**
 * Assert the viewer is an admin, throwing a DomainException otherwise. The service-layer
 * counterpart to require_role(): use inside service methods (caller catches DomainException
 * → flash error), vs require_role()'s hard 403 abort at controller entry.
 */
function assert_admin(array $viewer): void
{
    if ((string) ($viewer['role'] ?? \App\Support\Role::GUEST) !== \App\Support\Role::ADMIN) {
        throw new DomainException('เฉพาะผู้ดูแลระบบเท่านั้น');
    }
}

/** True when the role has elevated (management) rights — manager or admin. Predicate for conditional checks. */
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
