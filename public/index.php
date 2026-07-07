<?php
declare(strict_types=1);

use App\Controllers\SetupController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SettingsRepository;

[$container, $router] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $request = Request::capture();
    $container->instance(Request::class, $request);

    $requestPath = '/' . trim((string) $request->path, '/');
    $allowedDuringSetup = str_starts_with($requestPath, '/setup')
        || str_starts_with($requestPath, '/branding/');
    if (!$allowedDuringSetup) {
        // Redirect to /setup only when setup is genuinely pending: no completed flag AND no admin yet.
        // Mirrors SetupController::show()/execute() so a seed/admin-provisioned deploy (admin exists,
        // flag not set) doesn't bounce /setup ↔ /login forever.
        $settings = $container->get(SettingsRepository::class);
        $pdo = $container->get(PDO::class);
        if (SetupController::requiresSetupRedirect($settings, $pdo)) {
            Response::redirect('/setup');
        }
    }

    $router->dispatch($request, $container);
} catch (Throwable $exception) {
    if ((bool) config('app.debug', false)) {
        throw $exception;
    }

    Response::abort(500, 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
}
