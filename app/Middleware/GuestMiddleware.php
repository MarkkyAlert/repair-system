<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Response;

class GuestMiddleware
{
    public static function handle(): void
    {
        if (!auth()->check()) {
            return;
        }

        Response::redirect('/dashboard');
    }
}
