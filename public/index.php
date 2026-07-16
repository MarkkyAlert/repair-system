<?php
declare(strict_types=1);

use App\Controllers\SetupController;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SettingsRepository;

[$container, $router] = require dirname(__DIR__) . '/bootstrap.php';

// Fail fast on a leaky misconfiguration: APP_DEBUG=true under APP_ENV=production makes the handler below
// rethrow and expose stack traces to clients. Refuse to serve rather than leak (local dev / CLI unaffected).
if (is_unsafe_production_debug((string) config('app.env', 'production'), (bool) config('app.debug', false))) {
    error_log('[config] refusing to serve: APP_DEBUG=true with APP_ENV=production would leak stack traces to clients — set APP_DEBUG=false');
    http_response_code(500);
    exit('Server misconfigured (debug enabled in production). See the server log.');
}

// Set the static security headers (nosniff / X-Frame-Options / Referrer-Policy) in code so they hold on any
// server, not just Apache-with-.htaccess. Done before dispatch so every response — including the /setup
// redirect below and file downloads — carries them. CSP is emitted per-response in View::render (needs the nonce).
emit_security_headers();

// Correlation id on every response so a user can quote a reference that ties to the server log. (error-review F8)
if (!headers_sent()) {
    header('X-Request-Id: ' . request_id());
}

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
    // Always log the uncaught exception (with trace) to the server log — otherwise an unexpected prod 500 is
    // invisible to the team. In debug, also rethrow so it surfaces on screen; in prod, show a safe generic page.
    log_uncaught_exception($exception);

    if ((bool) config('app.debug', false)) {
        throw $exception;
    }

    Response::abort(500, 'ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง');
}
