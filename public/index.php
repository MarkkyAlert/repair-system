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
        $settings = $container->get(SettingsRepository::class);
        if ($settings instanceof SettingsRepository && !SetupController::isSetupCompletedStatic($settings)) {
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
