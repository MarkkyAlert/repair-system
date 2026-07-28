<?php

declare(strict_types=1);

use App\Services\AssetService;
use App\Services\EmailTemplateService;
use App\Services\GuestTicketService;
use App\Services\ReferenceDataService;
use App\Services\TicketWorkflowService;

// Pre-ship sweep M-1: the free-text fields that land in TEXT columns (65,535 BYTES) were validated only for
// non-empty — never length. A technician pasting a long device log into the resolution field (~64KB+, or ~22k
// Thai chars) would, on a strict MySQL/MariaDB server (this project's default), throw "Data too long" as an
// uncaught PDOException → 500 → the whole transaction rolls back → the resolution they just typed is lost. On a
// non-strict server it truncates silently. The guard must count BYTES (not characters), because a Thai glyph is
// 3 bytes in utf8mb4, so a character-based cap of 65535 would still let ~196KB of Thai overflow the column.

/** A Thai string N characters long — 3 bytes each in utf8mb4, so byte length = 3 * N. */
function tlg_thai(int $chars): string
{
    return str_repeat('ก', $chars);
}

/** Exactly one byte beyond TEXT capacity, using 4-byte UTF-8 code points. */
function tlg_one_byte_over(): string
{
    return str_repeat("\u{1F600}", 16384);
}

test('M-1(helper): require_max_bytes counts BYTES, closing the gap a character cap leaves open for Thai', function (): void {
    // Exactly at the TEXT limit passes; one byte over throws.
    require_max_bytes(str_repeat('x', 65535), 65535, 'x'); // 65,535 bytes — allowed, no throw
    $threw = false;
    try {
        require_max_bytes(str_repeat('x', 65536), 65535, 'x'); // 65,536 bytes — one over
    } catch (DomainException) {
        $threw = true;
    }
    assert_true($threw, '65,536 bytes must be rejected');

    // The load-bearing case: 30,000 Thai chars = 90,000 bytes. A character cap of 65535 would WRONGLY accept this
    // (30,000 < 65,535) and the INSERT would still overflow TEXT. The byte guard must reject it.
    assert_true(mb_strlen(tlg_thai(30000)) < 65535, 'precondition: a char-based cap would pass this Thai string');
    $threw = false;
    try {
        require_max_bytes(tlg_thai(30000), 65535, 'บันทึก'); // 90,000 bytes
    } catch (DomainException) {
        $threw = true;
    }
    assert_true($threw, '30,000 Thai chars (90,000 bytes) must be rejected — proving the guard is byte-based, not char-based');
});

test('M-1: resolveAssignedWork rejects an over-long resolution with a friendly error — no DB crash, nothing written', function (): void {
    $id = wf_drive_to_status('in_progress');
    $tech = ['id' => 3, 'role' => 'technician'];
    try {
        // 21,846 Thai chars = 65,538 bytes — just past the TEXT limit. Before the fix this reached the INSERT and
        // (on strict mode) threw a raw PDOException; the assertion below demands the friendly DomainException instead.
        $threw = null;
        try {
            wf_service()->resolveAssignedWork($id, $tech, [
                'diagnosis_summary' => 'ok',
                'resolution_summary' => tlg_thai(21846),
                'labor_minutes' => 10,
            ]);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        assert_true($threw instanceof DomainException, 'must be a friendly DomainException, not a raw PDOException/crash (got ' . ($threw ? get_class($threw) : 'nothing') . ')');
        assert_contains_str('ยาวเกิน', (string) $threw->getMessage(), 'the message must tell the user the text is too long');
        // The whole point: the technician did not lose their work to a partial write — the ticket is still open.
        assert_same('in_progress', (string) (wf_state($id)['status'] ?? ''), 'the ticket must stay in_progress — nothing was written');
    } finally {
        wf_cleanup($id);
    }
});

test('M-1: cancelTicket also rejects an over-long reason (breadth across the workflow text fields)', function (): void {
    $id = wf_drive_to_status('pending_approval');
    $requester = ['id' => 1, 'role' => 'requester'];
    try {
        $threw = null;
        try {
            wf_service()->cancelTicket($id, $requester, ['cancel_note' => str_repeat('x', 65536)]);
        } catch (\Throwable $e) {
            $threw = $e;
        }
        assert_true($threw instanceof DomainException, 'cancel note over the limit must be a friendly DomainException (got ' . ($threw ? get_class($threw) : 'nothing') . ')');
        assert_same('pending_approval', (string) (wf_state($id)['status'] ?? ''), 'the ticket must not be cancelled by an over-long note');
    } finally {
        wf_cleanup($id);
    }
});

test('M-1: AssetService rejects over-long notes with a friendly error, not a DB crash', function (): void {
    $service = tvm_container()->get(AssetService::class);
    // The ASSET form reference (its 'categories' are asset categories) — with VALID ids so, once the guard is
    // removed, the flow reaches the real INSERT and crashes there, rather than stopping at a category/location check.
    $ref = tvm_container()->get(App\Repositories\AssetRepository::class)->getAssetFormReferenceData();
    $admin = ['id' => 4, 'role' => 'admin'];

    $threw = null;
    try {
        $service->createAsset($admin, [
            'asset_code' => 'TLG-' . substr(bin2hex(random_bytes(4)), 0, 6),
            'name' => 'text-length probe',
            'asset_category_id' => (int) ($ref['categories'][0]['id'] ?? 0),
            'location_id' => (int) ($ref['locations'][0]['id'] ?? 0),
            'status' => 'active',
            'notes' => tlg_thai(30000), // 90,000 bytes into a TEXT column
        ]);
    } catch (\Throwable $e) {
        $threw = $e;
    }
    // Defensive: on a non-strict server without the guard the INSERT would truncate-and-succeed; make sure no
    // probe asset survives this test either way.
    tvm_container()->get(PDO::class)->exec("DELETE FROM assets WHERE asset_code LIKE 'TLG-%'");

    assert_true($threw instanceof DomainException, 'over-long asset notes must be a friendly DomainException, not a PDOException (got ' . ($threw ? get_class($threw) : 'nothing — the asset was created!') . ')');
    assert_contains_str('ยาวเกิน', (string) $threw->getMessage(), 'the message must name the too-long field');
});

test('M-1b: approval note rejects a 65,536-byte value before the ticket transition writes', function (): void {
    $id = wf_drive_to_status('pending_approval');
    $before = wf_transition_snapshot($id);
    $error = null;

    try {
        try {
            wf_service()->approveTicket(
                $id,
                ['id' => 4, 'role' => 'admin'],
                ['note' => tlg_one_byte_over()]
            );
        } catch (Throwable $exception) {
            $error = $exception;
        }

        assert_true($error instanceof DomainException, 'approval note must fail cleanly before strict DB overflow (got ' . ($error ? get_class($error) : 'nothing') . ')');
        assert_contains_str('ยาวเกิน', $error->getMessage(), 'approval note names the length problem');
        assert_same($before, wf_transition_snapshot($id), 'approval overflow leaves ticket and side effects unchanged');
    } finally {
        wf_cleanup($id);
    }
});

test('M-1b: guest-request rejection note rejects a 65,536-byte value and keeps the request new', function (): void {
    $id = gc_insert_request();
    $error = null;

    try {
        try {
            tvm_container()->get(GuestTicketService::class)->rejectRequest(
                $id,
                ['id' => 4, 'role' => 'admin'],
                tlg_one_byte_over()
            );
        } catch (Throwable $exception) {
            $error = $exception;
        }

        assert_true($error instanceof DomainException, 'guest rejection note must fail cleanly (got ' . ($error ? get_class($error) : 'nothing') . ')');
        assert_contains_str('ยาวเกิน', $error->getMessage(), 'guest rejection note names the length problem');
        assert_same('new', gc_request_status($id), 'the rejected request is not partially changed');
    } finally {
        gc_cleanup($id, []);
    }
});

test('M-1b: master-data descriptions reject over-capacity bytes before any admin row is written', function (): void {
    $pdo = tvm_container()->get(PDO::class);
    $code = 'TLGD-' . strtoupper(bin2hex(random_bytes(4)));
    $error = null;

    try {
        try {
            tvm_container()->get(ReferenceDataService::class)->createDepartment(
                ['id' => 4, 'role' => 'admin'],
                ['code' => $code, 'name' => 'Text guard department', 'description' => tlg_one_byte_over(), 'is_active' => '1']
            );
        } catch (Throwable $exception) {
            $error = $exception;
        }

        assert_true($error instanceof DomainException, 'master description must fail cleanly (got ' . ($error ? get_class($error) : 'nothing') . ')');
        assert_contains_str('ยาวเกิน', $error->getMessage(), 'master description names the length problem');
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM departments WHERE code = ?');
        $stmt->execute([$code]);
        assert_same(0, (int) $stmt->fetchColumn(), 'no department is inserted');
    } finally {
        $pdo->prepare('DELETE FROM departments WHERE code = ?')->execute([$code]);
    }
});

test('M-1b: email-template TEXT fields reject over-capacity bytes atomically', function (): void {
    $service = tvm_container()->get(EmailTemplateService::class);
    $repo = etpl_repo();
    $key = 'system_announcement';
    $before = $repo->getByKey($key);
    $error = null;

    try {
        $service->saveOverrides($key, [
            'heading' => tlg_one_byte_over(),
            'intro' => 'unchanged',
            'footer_note' => 'unchanged',
        ], 4);
    } catch (Throwable $exception) {
        $error = $exception;
    }

    assert_true($error instanceof DomainException, 'email template must fail cleanly (got ' . ($error ? get_class($error) : 'nothing') . ')');
    assert_contains_str('ยาวเกิน', $error->getMessage(), 'email template names the length problem');
    assert_same($before, $repo->getByKey($key), 'no template field changes when one field is over capacity');
});

test('M-1b: CSV asset import marks over-capacity notes invalid during preview, before execute', function (): void {
    $ref = ai_ref();
    $result = ai_service()->validateRows([
        ai_raw($ref, ['notes' => tlg_one_byte_over()]),
    ]);

    assert_same(0, count($result['valid']), 'over-capacity notes must not reach executeImport');
    assert_same(1, count($result['invalid']), 'the preview identifies the bad row');
    assert_true(ai_has_error($result['invalid'][0], 'notes'), 'the row explains that notes is too long');
});
