<?php
declare(strict_types=1);

// Locks the JSON envelope that every AJAX/command endpoint returns via Response::jsonError / jsonSuccess.
// Response::json exits, so it can't be called in-process — instead this runs it in a subprocess and inspects
// the emitted body. Guards the support/frontend contract: errors are {success:false, message, ...extra} and
// success is {success:true, message, ...extra}, and — critically — no stack trace / exception detail leaks
// into the response. (The HTTP status is set via http_response_code, which the CLI SAPI can't surface to
// stdout, so it is not asserted here.)

/** Run a Response:: call in a subprocess and return its decoded JSON body. */
function api_capture(string $call): array
{
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $code = 'require ' . var_export($autoload, true) . '; ' . $call;
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');

    return json_decode((string) $out, true) ?: [];
}

test('api(error-response): jsonError emits {success:false, message, ...extra} with no trace/exception leak', function (): void {
    $data = api_capture('\App\Core\Response::jsonError("invalid input", 422, ["field" => "email"]);');

    assert_same(false, $data['success'] ?? null, 'error envelope carries success:false');
    assert_same('invalid input', $data['message'] ?? null, 'the user-facing message is present');
    assert_same('email', $data['field'] ?? null, 'caller-supplied extra fields pass through');
    assert_false(isset($data['trace']), 'no stack trace leaks to the client');
    assert_false(isset($data['exception']), 'no exception detail leaks to the client');
});

test('api(success-response): jsonSuccess emits {success:true, message, ...extra}', function (): void {
    $data = api_capture('\App\Core\Response::jsonSuccess(["id" => 7], "saved");');

    assert_same(true, $data['success'] ?? null, 'success envelope carries success:true');
    assert_same('saved', $data['message'] ?? null, 'the message is present');
    assert_same(7, $data['id'] ?? null, 'caller-supplied payload passes through');
});
