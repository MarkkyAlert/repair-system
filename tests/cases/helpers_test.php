<?php
declare(strict_types=1);

// ── normalize_date_range (the reversed-range dashboard bug fix) ──
test('normalize_date_range: normal order kept', function (): void {
    $r = normalize_date_range('2026-05-01', '2026-05-10');
    assert_same('2026-05-01 00:00:00', $r['from_datetime']);
    assert_same('2026-05-10 23:59:59', $r['to_datetime']);
});

test('normalize_date_range: reversed input is swapped (from<=to)', function (): void {
    $r = normalize_date_range('2026-05-10', '2026-05-01');
    assert_same('2026-05-01', $r['from_date']);
    assert_same('2026-05-10', $r['to_date']);
    assert_same('2026-05-01 00:00:00', $r['from_datetime']);
    assert_same('2026-05-10 23:59:59', $r['to_datetime']);
    assert_true($r['from_datetime'] <= $r['to_datetime'], 'invariant from<=to');
});

test('normalize_date_range: only-from / only-to / invalid / empty', function (): void {
    assert_same('2026-05-05 00:00:00', normalize_date_range('2026-05-05', '')['from_datetime']);
    assert_same('', normalize_date_range('2026-05-05', '')['to_datetime']);
    assert_same('', normalize_date_range('2026-5-1', '2026-05-10')['from_date'], 'invalid format rejected');
    assert_same('', normalize_date_range('', '')['from_datetime']);
});

// ── ticket status single source ──
test('ticket_status_values: 12 canonical values in order', function (): void {
    $v = ticket_status_values();
    assert_count(12, $v);
    assert_same('submitted', $v[0]);
    assert_same('closed', $v[11]);
});

test('ticket_status_options: includeAll prepends + Thai labels (no English fallback)', function (): void {
    $opts = ticket_status_options(true);
    assert_count(13, $opts);
    assert_same('', $opts[0]['value']);
    assert_same('ทุกสถานะ', $opts[0]['label']);
    $byValue = [];
    foreach ($opts as $o) {
        $byValue[$o['value']] = $o['label'];
    }
    assert_same('รออนุมัติ', $byValue['pending_approval'], 'was English "Pending Approval" before the fix');
    assert_same('กำลังดำเนินการ', $byValue['in_progress']);
});

// ── terminal statuses ──
test('ticket_terminal_statuses: 5 incl resolved + SQL fragment', function (): void {
    $t = ticket_terminal_statuses();
    assert_count(5, $t);
    assert_true(in_array('resolved', $t, true), 'resolved is lifecycle-terminal');
    assert_same("'resolved','completed','rejected','cancelled','closed'", ticket_terminal_statuses_sql());
});

// ── severity + asset status ──
test('severity_values / asset_status_values match schema enums', function (): void {
    assert_same(['low', 'medium', 'high', 'critical'], severity_values());
    assert_same(['active', 'maintenance', 'retired', 'disposed'], asset_status_values());
});

test('priority_label_th canonical + fallback', function (): void {
    assert_same('ปานกลาง', priority_label_th('MEDIUM'));
    assert_same('เร่งด่วน', priority_label_th('URGENT'));
    assert_same('Unknownlevel', priority_label_th('unknownlevel'), 'humanize fallback');
});

// ── truthy_input (boolean parsing consolidation) ──
test('truthy_input: accepts 1/true/yes/on (case-insensitive), rejects rest', function (): void {
    foreach (['1', 'true', 'yes', 'on', 'On', 'YES', 'True'] as $v) {
        assert_true(truthy_input($v), "truthy: $v");
    }
    foreach (['0', '', 'no', 'off', 'false', 'nope'] as $v) {
        assert_false(truthy_input($v), "falsy: $v");
    }
});

// ── paginate clamping ──
test('paginate clamps page into range + computes offset', function (): void {
    assert_same(['page' => 3, 'offset' => 40, 'totalPages' => 3], paginate(99, 20, 50));
    assert_same(['page' => 1, 'offset' => 0, 'totalPages' => 1], paginate(0, 20, 0));
});

// ── is_valid_username: shared by admin create + CSV import (a-z 0-9 . - _, 3–50 chars) ──
test('is_valid_username: accepts valid, rejects bad case/chars/length', function (): void {
    assert_true(is_valid_username('somchai.01'), 'lowercase + digits + dot');
    assert_true(is_valid_username('a_b-c'), 'underscore + dash, 5 chars');
    assert_false(is_valid_username('ab'), 'too short (< 3)');
    assert_false(is_valid_username(str_repeat('a', 51)), 'too long (> 50)');
    assert_false(is_valid_username('Somchai'), 'uppercase not allowed');
    assert_false(is_valid_username('has space'), 'space not allowed');
    assert_false(is_valid_username('bad@name'), '@ not allowed');
});

// ── assert_admin: the shared admin-only guard (service/controller counterpart to require_role) ──
test('assert_admin: lets admins through, throws the standard message for everyone else', function (): void {
    assert_admin(['role' => 'admin']); // must NOT throw (reaching the next line proves it)

    foreach (['manager', 'technician', 'requester', 'guest', ''] as $role) {
        $err = '';
        try {
            assert_admin(['role' => $role]);
        } catch (DomainException $exception) {
            $err = $exception->getMessage();
        }
        assert_same('เฉพาะผู้ดูแลระบบเท่านั้น', $err, "role '$role' rejected");
    }

    $missing = '';
    try {
        assert_admin([]); // no role key → treated as guest → rejected
    } catch (DomainException $exception) {
        $missing = $exception->getMessage();
    }
    assert_same('เฉพาะผู้ดูแลระบบเท่านั้น', $missing, 'missing role rejected');
});
