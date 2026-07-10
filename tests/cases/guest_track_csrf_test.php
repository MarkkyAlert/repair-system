<?php
declare(strict_types=1);

use App\Controllers\GuestStatusController;
use App\Services\GuestTicketService;

// Regression for the real-HTTP API-test finding: POST /track with no _csrf returned a 500 because
// GuestStatusController::lookup() called csrf_validate() OUTSIDE its try (and the try caught only
// DomainException) — csrf_validate() throws RuntimeException, which escaped to the entry-point handler.
// /track is a PUBLIC guest form, so an expired/missing token (e.g. a stale tab) must render a friendly
// error, not a 500. The fix moves csrf_validate() inside the try and catches RuntimeException too.
//
// The action ends in Response::view (which exits), so it can't be driven over HTTP in-process; a spy
// subclass overrides the protected render() to capture the outcome while the real lookup() body runs.
test('guest /track: a POST with no CSRF token renders an error, not an uncaught 500', function (): void {
    $originalPost = $_POST;
    $_POST = ['request_no' => 'ABC-123', 'contact' => '0812345678']; // no _csrf → csrf_validate() throws

    try {
        $spy = new class (tvm_container()->get(GuestTicketService::class)) extends GuestStatusController {
            public bool $rendered = false;
            public ?string $renderedError = null;

            protected function render(string $ref, ?array $result, ?string $error): void
            {
                $this->rendered = true;
                $this->renderedError = $error;
            }
        };

        $escaped = null;
        try {
            $spy->lookup();
        } catch (\Throwable $exception) {
            $escaped = $exception; // before the fix: the CSRF RuntimeException escapes lookup() → the 500
        }

        assert_same(null, $escaped, 'the CSRF error must be handled inside lookup(), not escape as a 500');
        assert_true($spy->rendered, 'lookup() rendered the track form instead of 500-ing');
        assert_true(
            $spy->renderedError !== null && $spy->renderedError !== '',
            'a CSRF error message is shown to the guest'
        );
    } finally {
        $_POST = $originalPost;
    }
});
