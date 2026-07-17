<?php

declare(strict_types=1);

use App\Repositories\NotificationPreferenceRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\TicketReadRepository;
use App\Repositories\UserRepository;
use App\Services\EmailQueueService;
use App\Services\NotificationService;

// consistency-review F2: the notification-preference WRITE was built + upserted inside AuthController, unlike
// every other mutation which goes through a service. It now lives in NotificationService::saveUserPreferences
// (whose dependency is the same preference repo), and the controller delegates.

test('notification-prefs(F2): saveUserPreferences normalizes the form matrix (checkbox present = on) for every type', function (): void {
    // spy repo captures the normalized matrix without touching the DB
    $spyPrefs = new class () extends NotificationPreferenceRepository {
        /** @var array<string, array{email: bool, in_app: bool}> */
        public array $captured = [];

        public function __construct()
        {
        }

        public function upsertMatrix(int $userId, array $matrix): void
        {
            $this->captured = $matrix;
        }
    };

    $c = tvm_container();
    $svc = new NotificationService(
        $c->get(NotificationRepository::class),
        $c->get(TicketReadRepository::class),
        $c->get(EmailQueueService::class),
        $spyPrefs,
        $c->get(UserRepository::class),
    );

    // a partial form: one type email-only, another in_app-only, the rest absent
    $svc->saveUserPreferences(42, [
        'ticket_approved' => ['email' => 'on'],
        'comment_added' => ['in_app' => '1'],
    ]);

    assert_same(['email' => true, 'in_app' => false], $spyPrefs->captured['ticket_approved'] ?? [], 'a present email checkbox → email on, in_app off');
    assert_same(['email' => false, 'in_app' => true], $spyPrefs->captured['comment_added'] ?? [], 'a present in_app checkbox → in_app on, email off');
    assert_same(['email' => false, 'in_app' => false], $spyPrefs->captured['sla_breached'] ?? [], 'an absent type defaults to both off');
    assert_same(
        count(NotificationService::NOTIFICATION_TYPES),
        count($spyPrefs->captured),
        'the full matrix is persisted — every notification type, not only the ones in the form'
    );
});

test('notification-prefs(F2): AuthController delegates the preference write to the service, not the repo directly', function (): void {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/AuthController.php');

    assert_contains_str('$this->notifications->saveUserPreferences(', $src, 'the controller delegates the write to NotificationService');
    assert_true(!str_contains($src, '$this->preferences->upsertMatrix('), 'the controller no longer builds + upserts the matrix itself');
});
