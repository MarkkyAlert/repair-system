<?php
declare(strict_types=1);

use App\Services\BroadcastService;
use App\Services\MailerService;

// The email sender NAME follows MAIL_FROM_NAME when it is set (an ops override, e.g. to align DMARC or
// use a department name), and otherwise falls back to the admin-editable system name (setting app_name)
// — template-review F2. Before this, .env.example shipped MAIL_FROM_NAME="Repair System" and the config
// default was APP_NAME, so the setting() fallback was unreachable and a buyer who renamed the system in
// Admin still sent as "Repair System". resolveFromName is the one place both the real send
// (MailerService::send) and the admin mail-diagnostics display resolve the name, so they never diverge.
test('MailerService::resolveFromName: explicit MAIL_FROM_NAME wins; empty falls back to the app name', function (): void {
    assert_same(
        'Acme Repairs',
        MailerService::resolveFromName('Acme Repairs', 'ชื่อระบบจาก Admin'),
        'a configured from-name is used verbatim (ops override wins)'
    );
    assert_same(
        'ชื่อระบบจาก Admin',
        MailerService::resolveFromName('', 'ชื่อระบบจาก Admin'),
        'an empty from-name falls back to the app name (the admin-editable system name)'
    );
    assert_same(
        'ชื่อระบบจาก Admin',
        MailerService::resolveFromName('   ', 'ชื่อระบบจาก Admin'),
        'a whitespace-only from-name also falls back'
    );
    assert_same(
        'Acme',
        MailerService::resolveFromName('  Acme  ', 'ชื่อระบบจาก Admin'),
        'a configured from-name is trimmed'
    );
});

// error-review F5: the 'log' mail driver persists mail to disk indefinitely, so it must NOT store a live
// password-reset token in plaintext, and two messages in the same second must not overwrite each other.
test('MailerService::redactSecrets: strips reset tokens (path + query forms), leaving a [REDACTED] marker', function (): void {
    $token = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';
    $scrubbed = MailerService::redactSecrets("link https://h/reset-password/$token?email=u%40x.test and ?token=$token");

    assert_true(!str_contains($scrubbed, $token), 'the raw reset token must not survive redaction');
    assert_contains_str('/reset-password/[REDACTED]', $scrubbed, 'the path-form token is redacted');
    assert_contains_str('token=[REDACTED]', $scrubbed, 'the query-form token is redacted');
});

test('MailerService::send(log): writes a redacted, non-colliding mail-log artifact (error-review F5)', function (): void {
    $container = tvm_container();
    $config = $container->get('config');
    $dir = sys_get_temp_dir() . '/f5maillog_' . bin2hex(random_bytes(4));
    $tweaked = $config;
    $tweaked['mail']['driver'] = 'log';
    $tweaked['mail']['log_path'] = $dir;
    $tweaked['app']['env'] = 'local'; // dev keeps full content — this test covers the token-redaction path
    $container->instance('config', $tweaked);

    $token = 'deadbeefdeadbeefdeadbeefdeadbeef';
    $msg = static fn (): array => [
        'to_email' => 'user@x.test',
        'subject' => 'Reset your password',
        'body_html' => "<a href=\"https://h/reset-password/$token?email=user%40x.test\">reset</a>",
        'body_text' => "https://h/reset-password/$token?email=user%40x.test",
        'payload' => ['template' => 'password_reset', 'reset_url' => "https://h/reset-password/$token?email=user%40x.test"],
    ];

    try {
        $mailer = $container->get(MailerService::class);
        $mailer->send($msg()); // same subject, same second
        $mailer->send($msg());

        $files = glob($dir . '/*.json') ?: [];
        assert_same(2, count($files), 'two same-subject messages in the same second produce TWO files, not one (lossless)');

        $contents = (string) file_get_contents($files[0]) . (string) file_get_contents($files[1]);
        assert_true(!str_contains($contents, $token), 'the live reset token must NOT be persisted in the mail log');
        assert_contains_str('[REDACTED]', $contents, 'the token is redacted in the artifact');
    } finally {
        $container->instance('config', $config);
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
});

// error-review-2 F5: in production the log driver must not persist PII — mask the recipient, drop the body.
test('MailerService::maskEmail: keeps the first char + domain, hides the rest', function (): void {
    assert_same('a***@example.com', MailerService::maskEmail('alice@example.com'), 'a normal address is masked');
    assert_same('', MailerService::maskEmail(''), 'empty stays empty');
    assert_same('***', MailerService::maskEmail('notanemail'), 'a value with no @ is fully masked');
});

test('MailerService::send(log) in production: masks recipient + omits the body (no PII on disk) (error-review-2 F5)', function (): void {
    $container = tvm_container();
    $config = $container->get('config');
    $dir = sys_get_temp_dir() . '/f5prod_' . bin2hex(random_bytes(4));
    $tweaked = $config;
    $tweaked['mail']['driver'] = 'log';
    $tweaked['mail']['log_path'] = $dir;
    $tweaked['app']['env'] = 'production'; // production → PII must not be written
    $container->instance('config', $tweaked);

    try {
        $container->get(MailerService::class)->send([
            'to_email' => 'alice@example.com',
            'to_name' => 'Alice Secret',
            'subject' => 'Password reset',
            'body_html' => '<a href="https://h/reset-password/tok123?email=alice%40example.com">reset</a>',
            'body_text' => 'sensitive ticket details here',
        ]);

        $files = glob($dir . '/*.json') ?: [];
        assert_same(1, count($files), 'one artifact written');
        $content = (string) file_get_contents($files[0]);

        assert_true(!str_contains($content, 'alice@example.com'), 'the raw recipient email must NOT be on disk in production');
        assert_contains_str('a***@example.com', $content, 'the recipient is masked');
        assert_true(!str_contains($content, 'Alice Secret'), 'the recipient name is not persisted');
        assert_true(!str_contains($content, 'sensitive ticket details'), 'the body content is omitted in production');
        assert_true(!str_contains($content, 'tok123'), 'no token/URL survives (body omitted)');
    } finally {
        $container->instance('config', $config);
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
});

// error-review F7: an unsupported MAIL_DRIVER must be rejected loudly, not silently sent via PHPMailer's
// default mail() transport (an unconfigured sendmail that drops/misroutes mail with no diagnostic).
test('MailerService::send: an unsupported MAIL_DRIVER throws instead of falling through to mail() (error-review F7)', function (): void {
    $container = tvm_container();
    $config = $container->get('config');
    $bogus = $config;
    $bogus['mail']['driver'] = 'sendmailx';
    $container->instance('config', $bogus);

    try {
        $threw = false;
        try {
            $container->get(MailerService::class)->send(['to_email' => 'x@x.test', 'subject' => 's', 'body_html' => 'h', 'body_text' => 't']);
        } catch (\RuntimeException $e) {
            $threw = true;
            assert_contains_str('sendmailx', $e->getMessage(), 'the error names the unsupported driver');
        }
        assert_true($threw, 'an unknown driver must throw, not silently use mail()');
    } finally {
        $container->instance('config', $config);
    }
});

// error-review F7: the admin test-email surfaces a generic message, but the real cause (driver/host/port +
// exception) must be logged — it was discarded before, leaving a failed SMTP setup undiagnosable.
test('BroadcastService::sendTestEmail: a send failure is logged with the mail settings, then reported generically (error-review F7)', function (): void {
    $container = tvm_container();
    $config = $container->get('config');
    $bogus = $config;
    $bogus['mail']['driver'] = 'sendmailx';
    $container->instance('config', $bogus);

    $tmp = tempnam(sys_get_temp_dir(), 'testmail_') . '.log';
    $originalLog = (string) ini_get('error_log');

    try {
        ini_set('error_log', $tmp);
        $threw = false;
        try {
            $container->get(BroadcastService::class)->sendTestEmail(
                ['id' => 4, 'role' => 'admin', 'full_name' => 'Admin'],
                ['to_email' => 'ops@x.test', 'template' => 'password_reset']
            );
        } catch (\DomainException $e) {
            $threw = true;
            assert_contains_str('ส่งอีเมลทดสอบไม่สำเร็จ', $e->getMessage(), 'the admin sees a generic message (no raw cause)');
        }
        ini_set('error_log', $originalLog);

        assert_true($threw, 'the failure surfaces to the admin as a generic DomainException');
        assert_contains_str('[email.test.failed]', (string) @file_get_contents($tmp), 'the real cause is logged for diagnosis');
    } finally {
        ini_set('error_log', $originalLog);
        @unlink($tmp);
        $container->instance('config', $config);
    }
});
