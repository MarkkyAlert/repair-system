<?php

declare(strict_types=1);

// Pre-ship sweep (Layer 3, coverage gap): the admin console's CRUD matrix is asymmetric ON PURPOSE, but nothing
// locked those choices, so an accidental drift — a "delete user" route slipped in, or a category delete removed —
// would pass unnoticed. This route-inventory guard makes the intentional shape explicit:
//   * reference entities (department / ticket-category / asset-category / location / priority) = create+update+delete
//   * users                                                                                   = create+update, NO delete (deactivate-only; users own ticket/audit FKs)
//   * email templates                                                                         = edit/update/reset, NO create/delete (fixed system key set)
// A route added or removed against this matrix now fails CI, forcing a deliberate decision rather than silent drift.
// (Complements admin_route_gate/csrf_route_gate which check role+CSRF on whatever routes exist; this checks WHICH exist.)

$__routes = static fn (): string => (string) file_get_contents(BASE_PATH . '/config/routes.php');

test('admin CRUD matrix: reference entities all have create + update + delete', function () use ($__routes): void {
    $routes = $__routes();
    // [label, create POST, update POST, delete POST]
    $entities = [
        ['department', "post('/admin/departments'", "post('/admin/departments/{departmentId}'", "post('/admin/departments/{departmentId}/delete'"],
        ['ticket category', "post('/admin/categories'", "post('/admin/categories/{categoryId}'", "post('/admin/categories/{categoryId}/delete'"],
        ['asset category', "post('/admin/asset-categories'", "post('/admin/asset-categories/{categoryId}'", "post('/admin/asset-categories/{categoryId}/delete'"],
        ['location', "post('/admin/locations'", "post('/admin/locations/{locationId}'", "post('/admin/locations/{locationId}/delete'"],
        ['priority', "post('/admin/priorities'", "post('/admin/priorities/{priorityId}'", "post('/admin/priorities/{priorityId}/delete'"],
    ];
    foreach ($entities as [$label, $create, $update, $delete]) {
        assert_true(str_contains($routes, $create), "$label must have a create route");
        assert_true(str_contains($routes, $update), "$label must have an update route");
        assert_true(str_contains($routes, $delete), "$label must have a delete route");
    }
});

test('admin CRUD matrix: users are create+update only — NO hard delete route (deactivate-only by design)', function () use ($__routes): void {
    $routes = $__routes();
    assert_true(str_contains($routes, "post('/admin/users'"), 'users must have a create route');
    assert_true(str_contains($routes, "post('/admin/users/{userId}'"), 'users must have an update route');
    // The intentional asymmetry: a hard delete would orphan ticket/audit history, so users are deactivated, not deleted.
    // If this assertion ever fails, someone added a user-delete route — that must be a conscious product decision.
    assert_false(
        str_contains($routes, "/admin/users/{userId}/delete"),
        'users must NOT have a hard-delete route (lifecycle end is is_active=0); adding one is a deliberate decision'
    );
});

test('admin CRUD matrix: email templates are edit/update/reset only — NO create or delete (fixed key set)', function () use ($__routes): void {
    $routes = $__routes();
    assert_true(str_contains($routes, "/admin/email-templates/{templateKey}"), 'email templates must have an edit/update route');
    assert_true(str_contains($routes, 'reset'), 'email templates must offer reset-to-default');
    assert_false(
        str_contains($routes, "post('/admin/email-templates/{templateKey}/delete'"),
        'email templates are a fixed system set — no delete route by design'
    );
});
