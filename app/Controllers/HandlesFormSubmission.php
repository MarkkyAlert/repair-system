<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use DomainException;
use RuntimeException;

/**
 * Shared handler for authenticated form-POST actions: enforce auth (+ optional role),
 * validate CSRF, run the mutation, flash success/error, then redirect. Pass
 * $oldInputOnError to opt into form repopulation (clear on success / restore on error).
 * Single source for AdminController / EmailQueueController / GuestRequestController / TicketsController.
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
        } catch (DomainException|RuntimeException $exception) {
            if ($oldInputOnError !== null) {
                with_old_input($oldInputOnError);
            }
            flash('error', $exception->getMessage());
        }

        Response::redirect($redirectTo);
    }
}
