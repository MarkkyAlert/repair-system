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
