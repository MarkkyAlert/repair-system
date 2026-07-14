<?php
declare(strict_types=1);

use App\Support\Role;

// Pins the role single-source (app/Support/Role.php). The constant values must equal the users.role ENUM
// strings so Role::ADMIN is a drop-in for the literal 'admin', and assignable() must match what valid_roles()
// returns — the refactor that centralised roles preserves the exact list/values.
test('role: constants match the users.role ENUM and drive valid_roles()', function (): void {
    assert_same('requester', Role::REQUESTER);
    assert_same('manager', Role::MANAGER);
    assert_same('technician', Role::TECHNICIAN);
    assert_same('admin', Role::ADMIN);
    assert_same('guest', Role::GUEST);

    // Assignable list order mirrors the ENUM; guest is the not-signed-in fallback, not a stored role.
    assert_same(['requester', 'manager', 'technician', 'admin'], Role::assignable());
    assert_same(Role::assignable(), valid_roles(), 'valid_roles() must delegate to Role::assignable()');

    assert_true(Role::isValid('admin'));
    assert_true(Role::isValid('requester'));
    assert_false(Role::isValid('guest'), 'guest is not an assignable/stored role');
    assert_false(Role::isValid('superuser'), 'unknown roles are rejected');
});

// F5 (logic review): the admin role-preview text claimed admin could "รับงาน เริ่มงาน และปิดงานซ่อม", but
// TicketPolicy grants hands-on technician work only to the assigned technician (admin manages/assigns). This
// locks the two together — the behavioural truth (policy) AND the documentation (preview) must agree.
test('role(F5): admin does NOT do hands-on technician work — policy and role-preview agree', function (): void {
    $policy = new App\Services\TicketPolicy();
    $admin = ['id' => 4, 'role' => 'admin'];
    $assigned = ['status' => 'assigned', 'approval_status' => 'approved', 'assigned_technician_id' => 3];
    $inProgress = ['status' => 'in_progress', 'approval_status' => 'approved', 'assigned_technician_id' => 3];

    // behavioural truth
    assert_false($policy->canAcceptTechnicianWork($assigned, $admin), 'admin cannot accept technician work');
    assert_false($policy->canStartTechnicianWork($assigned, $admin), 'admin cannot start technician work');
    assert_false($policy->canResolveTechnicianWork($inProgress, $admin), 'admin cannot resolve technician work');

    // documentation truth — the preview capability list must not claim admin can
    $page = tvm_container()->get(App\Services\AdminService::class)->getAdminPageData($admin);
    $techWork = null;
    foreach ($page['rolePreview']['capabilities'] as $capability) {
        if (str_contains((string) ($capability['label'] ?? ''), 'รับงาน เริ่มงาน')) {
            $techWork = $capability;
            break;
        }
    }
    assert_true($techWork !== null, 'the technician-work capability row exists in the preview');
    assert_false(in_array('admin', (array) $techWork['roles'], true), 'the preview must NOT list admin for hands-on technician work');
});
