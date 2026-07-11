<?php
declare(strict_types=1);

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
