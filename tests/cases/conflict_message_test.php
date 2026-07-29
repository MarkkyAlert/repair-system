<?php
declare(strict_types=1);

use App\Repositories\AssetRepository;
use App\Repositories\UserRepository;
use App\Services\AdminService;

// B-9: when two people edit the same record, the optimistic lock correctly refuses the second save — but the
// refusal used to say only "someone else edited this, refresh and try again". The person then had to refresh,
// spot the difference themselves, and often just retyped their version straight over the other person's work.
// The rejection now quotes what is stored RIGHT NOW for the fields that actually differ, so the decision to
// overwrite is an informed one. Deliberately NOT a full diff view — the message rides in the existing toast.
// Covered elsewhere: the locks themselves (asset_update_test, admin_user_test, auth_test, comment_test).

function cflt_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

// ── the shared message builder ──

test('conflict(B-9): the message quotes the stored values, and falls back to the plain wording with none', function (): void {
    $withValues = optimistic_lock_message('ข้อมูล Asset ถูกแก้ไขโดยผู้ใช้อื่นแล้ว', ['สถานะ' => 'อยู่ระหว่างซ่อม', 'ที่ตั้ง' => 'อาคาร A']);
    assert_contains_str('ข้อมูล Asset ถูกแก้ไขโดยผู้ใช้อื่นแล้ว', $withValues, 'the lead sentence of each caller is kept');
    assert_contains_str('สถานะ “อยู่ระหว่างซ่อม”', $withValues, 'the stored value is quoted next to its field name');
    assert_contains_str('ที่ตั้ง “อาคาร A”', $withValues, 'a second differing field is listed too');
    assert_contains_str('ก่อนบันทึกทับ', $withValues, 'and the user is told to look before overwriting');

    // nothing readable to show (the row vanished, or the difference is in a field the message does not carry)
    // → the original wording, never a dangling "ตอนนี้ในระบบเป็น" with an empty list
    $bare = optimistic_lock_message('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว');
    assert_same('Comment ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง', $bare, 'the no-values case keeps the plain message');

    // boundary: the message lives in a one-line toast, so it stops at 3 fields and clips a long value
    $many = optimistic_lock_message('x', ['ก' => '1', 'ข' => '2', 'ค' => '3', 'ง' => '4']);
    assert_contains_str('ค “3”', $many, 'the third field is still shown');
    assert_true(!str_contains($many, 'ง “4”'), 'the fourth is dropped — the toast is one line, not a diff view');

    $long = optimistic_lock_message('x', ['ข้อความ' => str_repeat('ก', 80)]);
    assert_contains_str('…', $long, 'a long value is clipped with an ellipsis');
    assert_true(mb_strlen($long) < 120, 'the clipped message stays toast-sized');

    // an empty stored value must read as something, not as an empty pair of quotes
    assert_contains_str('เบอร์โทร “ไม่ระบุ”', optimistic_lock_message('x', ['เบอร์โทร' => '']), 'a blank stored value is spelled out');
});

test('conflict(B-9): only fields that actually differ are named, and a field the form did not send is not one', function (): void {
    $current = ['role' => 'technician', 'is_active' => 1, 'full_name' => 'ช่างเอ', 'email' => 'a@example.com', 'phone' => '081'];

    // the admin form sends every field; only the role differs → only the role is reported
    $changed = user_changed_fields(
        ['role' => 'requester', 'is_active' => '1', 'full_name' => 'ช่างเอ', 'email' => 'a@example.com', 'phone' => '081'],
        $current
    );
    assert_same(['บทบาท' => 'ช่างเทคนิค'], $changed, 'an unchanged field is never listed as if the other person had touched it');

    // the profile form sends no role / is_active — their absence must not read as "changed to blank"
    $profile = user_changed_fields(['full_name' => 'ชื่อใหม่', 'email' => 'a@example.com', 'phone' => '081'], $current);
    assert_same(['ชื่อ' => 'ช่างเอ'], $profile, 'fields the form does not carry are skipped, not diffed against nothing');
});

// ── end to end through the real repositories ──

test('conflict(B-9): a stale asset save is told which fields moved and what they are now', function (): void {
    /** @var AssetRepository $repo */
    $repo = tvm_container()->get(AssetRepository::class);
    $ref = $repo->getAssetFormReferenceData();
    $catId = (int) ($ref['categories'][0]['id'] ?? 0);
    $locId = (int) ($ref['locations'][0]['id'] ?? 0);
    $code = 'CFLT-' . strtoupper(bin2hex(random_bytes(3)));

    cflt_pdo()->prepare(
        "INSERT INTO assets (asset_code, name, asset_category_id, location_id, status, created_at, updated_at)
         VALUES (?, 'เครื่องพิมพ์ชั้น 2', ?, ?, 'active', NOW(), NOW())"
    )->execute([$code, $catId, $locId]);
    $id = (int) cflt_pdo()->lastInsertId();

    $base = [
        'asset_code' => $code, 'name' => 'เครื่องพิมพ์ชั้น 2', 'serial_number' => null,
        'asset_category_id' => $catId, 'department_id' => null, 'location_id' => $locId,
        'custodian_user_id' => null, 'brand' => null, 'model' => null, 'vendor' => null,
        'purchase_date' => null, 'warranty_expires_at' => null, 'status' => 'active', 'notes' => null,
    ];

    try {
        // person A sends the asset for repair (version 1 → 2)
        $repo->updateAsset($id, array_merge($base, ['status' => 'maintenance', 'original_version' => 1]));

        // person B still has the page from before and saves a rename, unaware of the repair
        $message = '';
        try {
            $repo->updateAsset($id, array_merge($base, ['name' => 'เครื่องพิมพ์ (ห้องบัญชี)', 'original_version' => 1]));
        } catch (DomainException $e) {
            $message = $e->getMessage();
        }

        assert_contains_str('ถูกแก้ไขโดยผู้ใช้อื่น', $message, 'the stale save is still rejected');
        assert_contains_str('สถานะ “อยู่ระหว่างซ่อม”', $message, "B is shown A's change — the asset is out for repair right now");
        assert_contains_str('ชื่อ “เครื่องพิมพ์ชั้น 2”', $message, 'and the stored name B was about to replace');
        assert_true(!str_contains($message, 'รหัส'), 'the asset code did not move, so it is not listed');
        assert_same(
            'maintenance',
            (string) cflt_pdo()->query("SELECT status FROM assets WHERE id = $id")->fetchColumn(),
            "the rejected save still did not overwrite A's work"
        );
    } finally {
        cflt_pdo()->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);
    }
});

test('conflict(B-9): a stale user edit names the role the other admin set; a stale profile save names the new name', function (): void {
    tvm_container()->instance(App\Core\Request::class, App\Core\Request::capture()); // AuditLogger needs a Request
    $suffix = bin2hex(random_bytes(4));
    cflt_pdo()->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (?, ?, "x", "สมชาย ก.", "requester", 1, NOW(), NOW())'
    )->execute(["cflt_$suffix", "cflt_$suffix@example.com"]);
    $userId = (int) cflt_pdo()->lastInsertId();
    $email = "cflt_$suffix@example.com";

    try {
        // admin A promotes the user to technician (version 1 → 2)
        tvm_container()->get(AdminService::class)->updateUser($userId, ['id' => 4, 'role' => 'admin'], [
            'full_name' => 'สมชาย ก.', 'email' => $email, 'role' => 'technician', 'is_active' => '1', 'original_version' => 1,
        ]);

        // admin B saves an old form that still says requester
        $adminMessage = '';
        try {
            tvm_container()->get(AdminService::class)->updateUser($userId, ['id' => 4, 'role' => 'admin'], [
                'full_name' => 'สมชาย ก.', 'email' => $email, 'role' => 'requester', 'is_active' => '1', 'original_version' => 1,
            ]);
        } catch (DomainException $e) {
            $adminMessage = $e->getMessage();
        }
        assert_contains_str('ข้อมูลผู้ใช้ถูกแก้ไขโดยผู้ใช้อื่นแล้ว', $adminMessage, 'the stale admin save is rejected');
        assert_contains_str('บทบาท “ช่างเทคนิค”', $adminMessage, 'B sees that the user is a technician now, before demoting them back');

        // the same row from the profile side: the user's own second tab holds the pre-rename version
        cflt_pdo()->prepare('UPDATE users SET full_name = "สมชาย (ฝ่ายบัญชี)", version = version + 1 WHERE id = ?')->execute([$userId]);
        $profileMessage = '';
        try {
            tvm_container()->get(UserRepository::class)->updateProfile($userId, [
                'full_name' => 'สมชาย', 'email' => $email, 'phone' => '', 'original_version' => 2,
            ]);
        } catch (DomainException $e) {
            $profileMessage = $e->getMessage();
        }
        assert_contains_str('ถูกแก้ไขจากอุปกรณ์อื่นแล้ว', $profileMessage, 'the stale profile save is rejected');
        assert_contains_str('ชื่อ “สมชาย (ฝ่ายบัญชี)”', $profileMessage, 'and quotes the name saved from the other device');
    } finally {
        cflt_pdo()->prepare('DELETE FROM audit_logs WHERE entity_type = "user" AND entity_id = ?')->execute([$userId]);
        cflt_pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    }
});
