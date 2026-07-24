<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Repositories\EmailQueueRepository;
use App\Repositories\GuestTicketRequestRepository;
use App\Repositories\UserRepository;
use App\Services\EmailQueueService;
use App\Services\EmailTemplateService;
use App\Services\GuestTicketService;
use App\Services\LoginRateLimiter;
use App\Services\MailerService;
use App\Services\NotificationService;

test('service pagination: pages beyond the end clamp before fetching email and guest queues', function (): void {
    $container = tvm_container();

    $emailQueue = new class () extends EmailQueueRepository {
        public int $lastOffset = -1;

        public function __construct()
        {
        }

        public function countByStatus(): array
        {
            return ['queued' => 26];
        }

        public function countJobs(string $status): int
        {
            return 26;
        }

        public function listJobs(string $status, int $limit, int $offset): array
        {
            $this->lastOffset = $offset;

            return $offset === 25 ? [['id' => 26]] : [];
        }
    };
    $emails = new EmailQueueService(
        $emailQueue,
        $container->get(UserRepository::class),
        $container->get(EmailTemplateService::class),
        $container->get(MailerService::class),
    );

    $emailResult = $emails->listJobsPaginated('all', 999, 25);
    assert_same(2, (int) $emailResult['pagination']['page'], 'email queue reports the real last page');
    assert_same(2, (int) $emailResult['pagination']['totalPages'], 'email queue has two pages');
    assert_same(25, $emailQueue->lastOffset, 'email query fetches the last page instead of an empty distant offset');
    assert_count(1, $emailResult['jobs'], 'the last email row remains visible');

    $guestRequests = new class () extends GuestTicketRequestRepository {
        public int $lastOffset = -1;

        public function __construct()
        {
        }

        public function countByStatus(): array
        {
            return ['new' => 26];
        }

        public function countMatching(string $status): int
        {
            return 26;
        }

        public function listByStatus(string $status, int $limit, int $offset): array
        {
            $this->lastOffset = $offset;

            return $offset === 25 ? [['id' => 26]] : [];
        }
    };
    $guests = new GuestTicketService(
        $guestRequests,
        $container->get(AssetRepository::class),
        $container->get(LoginRateLimiter::class),
        $container->get(NotificationService::class),
        $container->get(\PDO::class),
    );

    $guestResult = $guests->getModerationData('all', 999, 25);
    assert_same(2, (int) $guestResult['pagination']['page'], 'guest queue reports the real last page');
    assert_same(2, (int) $guestResult['pagination']['totalPages'], 'guest queue has two pages');
    assert_same(25, $guestRequests->lastOffset, 'guest query fetches the last page instead of an empty distant offset');
    assert_count(1, $guestResult['requests'], 'the last guest request remains visible');
});
