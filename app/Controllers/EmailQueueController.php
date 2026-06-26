<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Services\EmailQueueService;
use DomainException;
use RuntimeException;

class EmailQueueController
{
    public function __construct(private EmailQueueService $emailQueue)
    {
    }

    public function index(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        if ((string) ($viewer['role'] ?? 'guest') !== 'admin') {
            flash('error', 'เฉพาะผู้ดูแลระบบเท่านั้น');
            Response::redirect('/dashboard');
        }

        $query = request()?->query ?? [];
        $status = (string) ($query['status'] ?? '');
        $page = max(1, (int) ($query['page'] ?? 1));
        $data = $this->emailQueue->listJobsPaginated($status, $page);

        Response::view('admin/email-queue', [
            'title' => 'Email Queue',
            'pageHeading' => 'คิวอีเมล',
            'currentUser' => $viewer,
            'jobs' => $data['jobs'],
            'totals' => $data['totals'],
            'pagination' => $data['pagination'],
            'selectedStatus' => $status,
        ]);
    }

    public function retry(string $emailId): void
    {
        $this->handleUpdate(function () use ($emailId): void {
            $this->emailQueue->retryJob((int) $emailId);
        }, 'ส่งคำสั่งให้ลองอีเมลใหม่เรียบร้อยแล้ว', '/admin/email-queue');
    }

    private function handleUpdate(callable $callback, string $successMessage, string $redirectTo): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];

        try {
            csrf_validate();
            $callback($viewer);
            flash('success', $successMessage);
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect($redirectTo);
    }
}
