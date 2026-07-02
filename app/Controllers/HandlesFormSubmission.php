<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use DomainException;
use RuntimeException;

/**
 * Shared handler for authenticated form-POST actions: enforce auth (+ optional role),
 * validate CSRF, run the mutation, flash success/error, then redirect.
 * Single source for AdminController / EmailQueueController / GuestRequestController.
 */
trait HandlesFormSubmission
{
    protected function handleUpdate(
        callable $callback,
        string $successMessage = 'บันทึกข้อมูลเรียบร้อยแล้ว',
        string $redirectTo = '/admin',
        ?array $requireRoles = null,
        string $roleMessage = ''
    ): void {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        if ($requireRoles !== null) {
            require_role($viewer, $requireRoles, $roleMessage);
        }

        try {
            csrf_validate();
            $callback($viewer);
            flash('success', $successMessage);
        } catch (DomainException | RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($redirectTo);
    }
}
