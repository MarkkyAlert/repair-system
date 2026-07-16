<?php

declare(strict_types=1);

use App\Repositories\AdminRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\SettingsRepository;
use App\Services\AdminService;
use App\Services\AuditLogger;
use App\Services\BroadcastService;
use App\Services\EmailTemplateService;
use App\Services\MailerService;
use App\Services\NotificationService;

// error-review-4 F5: the user-management and test-email audit records stored the acted-upon user's RAW email +
// phone. Those are persistent records — in production they must not retain raw contact PII (the entry already
// identifies the target by id + full_name). Mask email/phone in production; dev/local keeps full values for
// debugging (same policy as the mail log). full_name is intentionally kept — it is the human-readable identity.

test('mask(F5): maskPhone keeps only the last 4 digits, hiding the rest', function (): void {
    assert_same('***5678', MailerService::maskPhone('0812345678'), 'a normal number shows only its last 4 digits');
    assert_same('***5678', MailerService::maskPhone('081-234-5678'), 'punctuation is stripped before masking');
    assert_same('***', MailerService::maskPhone('123'), 'a too-short number is fully masked (nothing to keep)');
    assert_same('', MailerService::maskPhone('   '), 'a blank phone stays blank');
});

test('audit(F5): the user audit masks email+phone in production but keeps full_name', function (): void {
    $spy = new class () extends AuditLogger {
        /** @var array<string, mixed> */
        public array $captured = [];

        public function __construct()
        {
        }

        public function record(array $viewer, string $action, string $entityType, ?int $entityId = null, array $context = []): void
        {
            $this->captured = $context;
        }
    };

    $c = tvm_container();
    $svc = new AdminService(
        $c->get(AdminRepository::class),
        $c->get(SettingsRepository::class),
        $c->get(AuditLogRepository::class),
        $spy,
        $c->get(EmailTemplateService::class),
        $c->get(LoginAttemptRepository::class),
    );
    $record = new ReflectionMethod($svc, 'recordAudit');
    $record->setAccessible(true);
    $raw = ['full_name' => 'สมชาย ใจดี', 'email' => 'somchai@example.com', 'phone' => '0812345678', 'role' => 'technician'];

    $config = $c->get('config');
    try {
        $prod = $config;
        $prod['app']['env'] = 'production';
        $c->instance('config', $prod);
        $record->invoke($svc, ['id' => 1], 'user.updated', 'user', 5, $raw);

        assert_same('s***@example.com', $spy->captured['email'] ?? '', 'the email is masked in the production audit');
        assert_same('***5678', $spy->captured['phone'] ?? '', 'the phone is masked in the production audit');
        assert_same('สมชาย ใจดี', $spy->captured['full_name'] ?? '', 'full_name is kept (the human-readable identity)');
        $json = (string) json_encode($spy->captured, JSON_UNESCAPED_UNICODE);
        assert_true(!str_contains($json, 'somchai@example.com'), 'the raw email never reaches the audit record');
        assert_true(!str_contains($json, '0812345678'), 'the raw phone never reaches the audit record');

        $local = $config;
        $local['app']['env'] = 'local';
        $c->instance('config', $local);
        $record->invoke($svc, ['id' => 1], 'user.updated', 'user', 5, $raw);
        assert_same('somchai@example.com', $spy->captured['email'] ?? '', 'dev/local keeps the full email for debugging');
        assert_same('0812345678', $spy->captured['phone'] ?? '', 'dev/local keeps the full phone for debugging');
    } finally {
        $c->instance('config', $config);
    }
});

test('audit(F5): the test-email audit masks the recipient address in production', function (): void {
    $spy = new class () extends AuditLogger {
        public bool $called = false;

        /** @var array<string, mixed> */
        public array $captured = [];

        public function __construct()
        {
        }

        public function record(array $viewer, string $action, string $entityType, ?int $entityId = null, array $context = []): void
        {
            $this->called = true;
            $this->captured = $context;
        }
    };
    // no-op mailer so the test does not actually send / write a mail-log file; maskEmail is a static call on
    // the real class, so masking is unaffected by this stand-in.
    $noopMailer = new class () extends MailerService {
        public function __construct()
        {
        }

        public function send(array $message): void
        {
        }
    };

    $c = tvm_container();
    $svc = new BroadcastService(
        $c->get(NotificationService::class),
        $noopMailer,
        $c->get(EmailTemplateService::class),
        $spy,
    );

    $config = $c->get('config');
    try {
        $prod = $config;
        $prod['app']['env'] = 'production';
        $c->instance('config', $prod);
        $svc->sendTestEmail(
            ['id' => 1, 'role' => 'admin', 'full_name' => 'Admin'],
            ['to_email' => 'recipient@example.com', 'template' => 'password_reset']
        );

        assert_true($spy->called, 'the audit is recorded after the (spied) send');
        assert_same('r***@example.com', $spy->captured['to_email'] ?? '', 'the recipient is masked in the production audit');
        assert_true(
            !str_contains((string) json_encode($spy->captured, JSON_UNESCAPED_UNICODE), 'recipient@example.com'),
            'the raw recipient address never reaches the audit record'
        );
    } finally {
        $c->instance('config', $config);
    }
});
