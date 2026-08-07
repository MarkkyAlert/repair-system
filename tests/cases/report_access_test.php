<?php
declare(strict_types=1);

use App\Services\ReportService;

// Cross-report guards (BI-review G3): (1) every report entry point is manager/admin-only — a non-manager
// is blocked at the service, so report data (which is org-wide, visibilityClause = 1=1 for those roles)
// never reaches a role that shouldn't see it; (2) exports do not error when the filter matches no data.

function ras_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

function ras_service(): ReportService
{
    return tvm_container()->get(ReportService::class);
}

// The list this used to carry named 10 page methods by hand. The service actually exposes 40-odd entry points
// once every export is counted, so three quarters of the surface was outside the net — and the net could not
// grow by itself: adding an eleventh report, or one more export format, silently added an ungated door unless
// somebody remembered to edit this file. Nothing was leaking when this was widened (every entry point was
// checked and every one of them denied a requester, a technician and a guest), which is exactly when to make
// the guard derive itself, while the answer is still "all of them".
//
// The rule the service follows: every public method takes the viewer first and starts by calling
// ensureCanViewReports. Reports are org-wide by design — visibilityClause is 1=1 for manager/admin — so an
// entry point that forgets the check hands the whole organisation's data to whoever asks.
test('reports: EVERY entry point blocks a non-manager — the list is derived from the class, not maintained by hand', function (): void {
    $svc = ras_service();

    $entryPoints = [];
    foreach ((new ReflectionClass(ReportService::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isConstructor() || $method->isStatic()) {
            continue;
        }
        $first = $method->getParameters()[0] ?? null;
        if ($first === null || $first->getName() !== 'viewer') {
            continue;
        }
        $entryPoints[] = $method;
    }

    assert_true(count($entryPoints) >= 38, 'sanity: the sweep really does find the report surface (found ' . count($entryPoints) . ')');

    /** stand-in values for anything the method needs beyond the viewer — the check must come first regardless */
    $fillerFor = static function (ReflectionParameter $p): mixed {
        $type = $p->getType() instanceof ReflectionNamedType ? $p->getType()->getName() : 'mixed';

        return match ($type) {
            'int' => 0,
            'string' => '',
            'bool' => false,
            'float' => 0.0,
            default => [],
        };
    };

    foreach (['technician', 'requester', 'guest'] as $role) {
        $viewer = ['id' => 1, 'role' => $role];
        foreach ($entryPoints as $method) {
            $args = [$viewer];
            foreach (array_slice($method->getParameters(), 1) as $parameter) {
                if ($parameter->isOptional()) {
                    break;
                }
                $args[] = $fillerFor($parameter);
            }

            $blocked = false;
            try {
                $method->invokeArgs($svc, $args);
            } catch (DomainException $e) {
                $blocked = str_contains($e->getMessage(), 'ไม่มีสิทธิ์เข้าถึงรายงาน');
            }
            assert_true($blocked, $method->getName() . '() must refuse a ' . $role . ' before doing any work');
        }
    }
});

test('reports: exports do not error when the filter matches no data', function (): void {
    $admin = ['id' => 4, 'role' => 'admin'];
    $emptyWindow = ['from_date' => '2099-01-01', 'to_date' => '2099-01-31']; // no tickets/logs in 2099
    $baselineJobId = (int) ras_pdo()->query('SELECT COALESCE(MAX(id), 0) FROM export_jobs')->fetchColumn();

    try {
        $csv = (string) ras_service()->exportReopenRateCsv($admin, $emptyWindow)['content'];
        assert_true(str_contains($csv, 'เปิดซ้ำ'), 'empty CSV still carries the header row (no error)');

        $xlsx = (string) ras_service()->exportReopenRateExcel($admin, $emptyWindow)['content'];
        assert_same('PK', substr($xlsx, 0, 2), 'empty XLSX is a valid workbook (no error)');

        $pdf = (string) ras_service()->exportReopenRatePdf($admin, $emptyWindow)['content'];
        assert_same('%PDF-', substr($pdf, 0, 5), 'empty PDF renders (no error, no fake numbers)');
    } finally {
        ras_pdo()->prepare('DELETE FROM export_jobs WHERE id > ?')->execute([$baselineJobId]);
    }
});

// ── B-4: ตัวกรองรายงานต้องเข้าถึงทุกอย่างที่รายงานแสดงได้ ──
// แถวของรายงานไม่เคยกรอง is_active (รายงานคือประวัติ) แต่ dropdown กรองแผนก/หมวดออก = เห็นตัวเลขแต่กดเจาะดูไม่ได้
// และหน้าเดียวกันยังไม่สม่ำเสมอ: priority/location เลือกของที่ปิดใช้งานได้ แต่ department/category เลือกไม่ได้
function ra_option_values(array $options): array
{
    return array_map(static fn (array $o): string => (string) $o['value'], $options);
}

test('reports B-4: a deactivated dimension is still selectable in every filter, and labelled', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $sfx = strtoupper(bin2hex(random_bytes(3)));
    $made = [];

    $pdo->prepare('INSERT INTO departments (code, name, is_active, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())')->execute(["B4D-$sfx", "B4 Dept $sfx"]);
    $made['department'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO ticket_categories (code, name, sort_order, is_active, created_at, updated_at) VALUES (?, ?, 95, 0, NOW(), NOW())')->execute(["B4C-$sfx", "B4 Cat $sfx"]);
    $made['category'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO locations (code, name, is_active, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())')->execute(["B4L-$sfx", "B4 Loc $sfx"]);
    $made['location'] = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO priorities (code, name, level, sort_order, response_time_minutes, resolution_time_minutes, is_active, created_at, updated_at)
                   VALUES (?, ?, (SELECT COALESCE(MAX(level),0)+1 FROM (SELECT level FROM priorities) p), 95, 60, 240, 0, NOW(), NOW())')->execute(["B4P-$sfx", "B4 Prio $sfx"]);
    $made['priority'] = (int) $pdo->lastInsertId();

    try {
        $filters = tvm_container()->get(App\Services\ReportService::class)
            ->getSlaBreachReportPage(['id' => 4, 'role' => 'admin'], [])['filters'];

        foreach (['department' => 'departmentOptions', 'category' => 'categoryOptions', 'priority' => 'priorityOptions', 'location' => 'locationOptions'] as $dim => $key) {
            $values = ra_option_values($filters[$key]);
            assert_true(
                in_array((string) $made[$dim], $values, true),
                "a deactivated $dim can still be selected — the report shows its rows, so the filter must reach them"
            );
            $label = '';
            foreach ($filters[$key] as $option) {
                if ((string) $option['value'] === (string) $made[$dim]) {
                    $label = (string) $option['label'];
                }
            }
            assert_contains_str('ปิดใช้งาน', $label, "the deactivated $dim is labelled so nobody picks it for new work by mistake");
        }
    } finally {
        $pdo->prepare('DELETE FROM departments WHERE id = ?')->execute([$made['department']]);
        $pdo->prepare('DELETE FROM ticket_categories WHERE id = ?')->execute([$made['category']]);
        $pdo->prepare('DELETE FROM locations WHERE id = ?')->execute([$made['location']]);
        $pdo->prepare('DELETE FROM priorities WHERE id = ?')->execute([$made['priority']]);
    }
});
test('reports B-4: the deactivated label matches the database exactly, in both directions', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $filters = tvm_container()->get(App\Services\ReportService::class)
        ->getSlaBreachReportPage(['id' => 4, 'role' => 'admin'], [])['filters'];

    $tables = [
        'departmentOptions' => 'departments',
        'categoryOptions' => 'ticket_categories',
        'priorityOptions' => 'priorities',
        'locationOptions' => 'locations',
    ];

    foreach ($tables as $key => $table) {
        $active = [];
        foreach ($pdo->query("SELECT id, is_active FROM $table")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $active[(int) $row['id']] = (bool) $row['is_active'];
        }

        foreach ($filters[$key] as $option) {
            $id = (int) $option['value'];
            if ($id <= 0) {
                continue; // the "all" entry
            }
            $labelled = str_contains((string) $option['label'], 'ปิดใช้งาน');
            assert_same(
                !($active[$id] ?? true),
                $labelled,
                "$key #$id: the deactivated label appears exactly when the row is deactivated — never on an active one"
            );
        }
    }
});
