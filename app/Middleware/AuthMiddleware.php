<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Response;

class AuthMiddleware
{
    public static function handle(?string $returnTo = null): void
    {
        if (auth()->check()) {
            return;
        }

        $target = $returnTo ?? request_path();
        Response::redirect('/login?return=' . rawurlencode($target));
    }
}
