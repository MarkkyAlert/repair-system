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
