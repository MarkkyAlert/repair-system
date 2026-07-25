<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Repositories\NotificationPreferenceRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\RememberMeService;

// bug-hunt R5-1: the profile edit form carries an optimistic-lock `original_version`, but showProfile built the
// 'profile' view-model WITHOUT `version`, so the hidden field fell back to `1` on every render. After the first
// successful self-edit (version → 2), the form still POSTed original_version=1, the version-locked UPDATE matched
// zero rows, and updateProfile threw a false "แก้ไขจากอุปกรณ์อื่น" error — permanently (a reload never fixed it,
// since the field was hardcoded). If an admin had ever edited the user (version ≥ 2), even the first self-edit
// failed. showProfile ends in Response::view (exit) behind AuthMiddleware::handle (needs a live session), so it
// can't be driven in-process; buildProfileData is the pure view-model seam it renders through — a spy calls it.
test('profile(R5-1): the profile view-model carries the real DB version, not a hardcoded 1', function (): void {
    $spy = new class (
        tvm_container()->get(AuthService::class),
        tvm_container()->get(NotificationPreferenceRepository::class),
        tvm_container()->get(UserRepository::class),
        tvm_container()->get(RememberMeService::class),
        tvm_container()->get(NotificationService::class),
    ) extends AuthController {
        /** @param array<string,mixed> $viewer @param array<string,mixed> $fresh @param array<string,mixed> $oldInput @return array<string,mixed> */
        public function probe(array $viewer, array $fresh, array $oldInput): array
        {
            return $this->buildProfileData($viewer, $fresh, $oldInput);
        }
    };

    // a user whose row has already advanced past version 1 (after one edit, or an admin edit)
    $data = $spy->probe(
        ['id' => 5, 'full_name' => 'Somchai', 'email' => 'a@x.test', 'phone' => '02', 'username' => 'somchai', 'role' => 'requester'],
        ['version' => 7, 'created_at' => '2026-01-01 00:00:00', 'last_login_at' => '2026-07-01 09:00:00'],
        []
    );

    assert_true(array_key_exists('version', $data), 'the profile view-model must include version (was omitted → form hardcoded to 1)');
    assert_same(7, $data['version'], 'the form carries the CURRENT DB version, not 1 — otherwise the 2nd consecutive save falsely fails');
    // the rest of the view-model is intact
    assert_same('Somchai', $data['full_name'], 'display fields still populated');
    assert_same('somchai', $data['username'], 'username preserved');
    assert_same('2026-07-01 09:00:00', $data['last_login_at'], 'last_login_at sourced from the fresh row');

    // old input (after a validation error) still overrides the editable fields, version still comes from the row
    $withOld = $spy->probe(
        ['id' => 5, 'full_name' => 'Old Name', 'email' => 'old@x.test', 'phone' => '', 'username' => 'somchai', 'role' => 'requester'],
        ['version' => 3, 'created_at' => '', 'last_login_at' => ''],
        ['full_name' => 'Typed Name', 'email' => 'typed@x.test', 'phone' => '099']
    );
    assert_same('Typed Name', $withOld['full_name'], 'old input wins for the editable field');
    assert_same(3, $withOld['version'], 'version still reflects the DB row on a re-render, so the retry can succeed');
});
