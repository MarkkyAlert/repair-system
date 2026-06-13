<?php
declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;

[$container, $router] = require dirname(__DIR__) . '/bootstrap.php';

try {
    $request = Request::capture();
    $container->instance(Request::class, $request);
    $router->dispatch($request, $container);
} catch (Throwable $exception) {
    if ((bool) config('app.debug', false)) {
        throw $exception;
    }

    Response::abort(500, 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
}
