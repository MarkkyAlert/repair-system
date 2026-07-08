<?php
declare(strict_types=1);

// The is_uploaded_file() shadow that lets updateLogo's origin guard pass in CLI (so the size/MIME checks run)
// now lives once in tests/shadow_functions.php, loaded before every case. See the note there.

namespace {

    use App\Core\Request;
    use App\Services\SystemSettingsService;

    // Validation tests for SystemSettingsService (admin system configuration). Drives the real service against
    // the test DB. Reject branches throw before any write. The happy paths write real rows into system_settings
    // — and because report tests read default_timezone/business_hours, every key touched is snapshotted before
    // and restored in finally so the shared settings never drift. updateLogo's move_uploaded_file() (the actual
    // save) can't run under CLI, so only its validation branches are covered here; the save is left to E2E.

    function ss_service(): SystemSettingsService
    {
        return tvm_container()->get(SystemSettingsService::class);
    }

    function ss_pdo(): PDO
    {
        return tvm_container()->get(PDO::class);
    }

    /** Bind a Request so AuditLogger::record() (called by the happy paths) can resolve one — production always
     *  has a bound Request; the CLI harness does not. Capture() defaults to GET "/". */
    function ss_bind_request(): void
    {
        tvm_container()->instance(Request::class, Request::capture());
    }

    function ss_admin(): array
    {
        return ['id' => 4, 'role' => 'admin'];
    }

    /** Current row (or null) for a setting key. */
    function ss_get(string $key): ?array
    {
        $stmt = ss_pdo()->prepare('SELECT setting_value, value_type, is_public, updated_by FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Snapshot the given keys → [key => row|null] so a later ss_restore() can undo any writes. */
    function ss_snapshot(array $keys): array
    {
        $snap = [];
        foreach ($keys as $key) {
            $snap[$key] = ss_get($key);
        }
        return $snap;
    }

    /** Restore a snapshot: UPDATE rows that existed back to their exact values, DELETE keys that were absent.
     *  Keeps the shared system_settings table clean even if a guard is (temporarily) disabled during a power-proof. */
    function ss_restore(array $snapshot): void
    {
        foreach ($snapshot as $key => $row) {
            if ($row === null) {
                ss_pdo()->prepare('DELETE FROM system_settings WHERE setting_key = ?')->execute([$key]);
                continue;
            }
            ss_pdo()->prepare('UPDATE system_settings SET setting_value = ?, value_type = ?, is_public = ?, updated_by = ? WHERE setting_key = ?')
                ->execute([$row['setting_value'], $row['value_type'], $row['is_public'], $row['updated_by'], $key]);
        }
    }

    /** Assert updateSetting($input) throws exactly $message (nothing is persisted on a reject). */
    function ss_reject_setting(array $input, string $message, string $ctx): void
    {
        $threw = false;
        try {
            ss_service()->updateSetting(ss_admin(), $input);
        } catch (DomainException $e) {
            $threw = true;
            assert_same($message, $e->getMessage(), $ctx);
        }
        assert_true($threw, "$ctx — must throw");
    }

    // ── updateSetting (freeform settings; 5 reject branches + happy) ──

    test('updateSetting: empty key, unknown type, and malformed JSON are rejected', function (): void {
        ss_reject_setting(['setting_key' => ''], 'กรุณาระบุ setting key', 'empty key');
        ss_reject_setting(['setting_key' => '   '], 'กรุณาระบุ setting key', 'whitespace-only key (trimmed to empty)');

        // a non-protected key so we reach the type/JSON guards (protected keys throw earlier)
        $freeKey = 'ss_qa_' . bin2hex(random_bytes(4));
        ss_reject_setting(['setting_key' => $freeKey, 'value_type' => 'xml'], 'ชนิดข้อมูลของ setting ไม่ถูกต้อง', 'unknown value_type');
        ss_reject_setting(['setting_key' => $freeKey, 'value_type' => 'json', 'setting_value' => '{not valid json'], 'ค่า setting แบบ JSON ไม่ถูกต้อง', 'malformed JSON value');

        // that key must never have been written by a reject
        assert_same(null, ss_get($freeKey), 'a rejected updateSetting persists nothing');
    });

    test('updateSetting(security): system-controlled keys and prefixes cannot be written via the generic endpoint', function (): void {
        // These keys own dedicated, validated forms (/admin/system-settings, /admin/settings/logo, /admin/categories/*).
        // Letting them through the freeform endpoint would bypass that validation — the guard blocks it. Snapshot +
        // restore so that if the guard is ever disabled (power-proof) the attempted writes don't poison the test DB.
        $protectedKeys = ['app_logo_path', 'app_name', 'default_timezone', 'ticket_prefix', 'business_hours', 'setup_completed'];
        $snapshot = ss_snapshot(array_merge($protectedKeys, ['category_sla_99']));
        try {
            foreach ($protectedKeys as $key) {
                ss_reject_setting(
                    ['setting_key' => $key, 'value_type' => 'string', 'setting_value' => 'hacked'],
                    'Setting key "' . $key . '" ถูกควบคุมโดยระบบ กรุณาแก้ผ่านฟอร์มเฉพาะ',
                    "protected key $key must be blocked"
                );
            }

            ss_reject_setting(
                ['setting_key' => 'category_sla_99', 'value_type' => 'string', 'setting_value' => 'hacked'],
                'Setting key prefix "category_sla_" ถูกควบคุมโดยระบบ',
                'protected prefix category_sla_ must be blocked'
            );

            // the guard fires before any upsert — the protected rows are untouched (app_name still its seed value)
            assert_same('Repair System', ss_get('app_name')['setting_value'] ?? null, 'app_name was NOT overwritten via the generic endpoint');
        } finally {
            ss_restore($snapshot);
        }
    });

    test('updateSetting(security): setup_completed is blocked here, but SetupController\'s own path still writes it', function (): void {
        // setup_completed gates the first-run /setup flow — an admin must not flip it through the freeform form.
        // But SetupController sets it via SettingsRepository::upsert() directly (not updateSetting), so protecting
        // the generic endpoint must NOT lock the legitimate setup path. Assert both halves.
        $snapshot = ss_snapshot(['setup_completed']);
        try {
            ss_reject_setting(
                ['setting_key' => 'setup_completed', 'value_type' => 'bool', 'setting_value' => '0'],
                'Setting key "setup_completed" ถูกควบคุมโดยระบบ กรุณาแก้ผ่านฟอร์มเฉพาะ',
                'setup_completed cannot be flipped via the generic endpoint'
            );

            // the repository path SetupController uses is unaffected by the endpoint guard
            $repo = tvm_container()->get(\App\Repositories\SettingsRepository::class);
            $repo->upsert('setup_completed', '1', 'bool', false, 4); // mirrors SetupController::completeSetup()
            assert_same('1', ss_get('setup_completed')['setting_value'] ?? null, 'the setup path can still set the flag');
        } finally {
            ss_restore($snapshot);
        }
    });

    test('updateSetting: a normal (non-protected) setting is persisted and read back', function (): void {
        $stringKey = 'ss_qa_' . bin2hex(random_bytes(4));
        $jsonKey = 'ss_qa_' . bin2hex(random_bytes(4));
        try {
            ss_bind_request();
            ss_service()->updateSetting(ss_admin(), [
                'setting_key' => $stringKey,
                'value_type' => 'string',
                'setting_value' => 'hello world',
                'is_public' => '1',
            ]);
            $row = ss_get($stringKey);
            assert_true($row !== null, 'string setting persisted');
            assert_same('hello world', $row['setting_value'], 'value stored verbatim');
            assert_same('string', $row['value_type'], 'type stored');
            assert_same(1, (int) $row['is_public'], 'is_public=1 from truthy "1"');

            // a valid JSON value passes the JSON guard and is stored verbatim (not re-encoded)
            ss_service()->updateSetting(ss_admin(), [
                'setting_key' => $jsonKey,
                'value_type' => 'json',
                'setting_value' => '{"a":1}',
                'is_public' => '0',
            ]);
            $jsonRow = ss_get($jsonKey);
            assert_same('{"a":1}', $jsonRow['setting_value'], 'valid JSON stored verbatim');
            assert_same('json', $jsonRow['value_type'], 'json type stored');
            assert_same(0, (int) $jsonRow['is_public'], 'is_public=0 from "0"');
        } finally {
            ss_pdo()->prepare('DELETE FROM system_settings WHERE setting_key IN (?, ?)')->execute([$stringKey, $jsonKey]);
        }
    });

    // ── updateSystemSettings (core system form; 5 reject branches + happy) ──

    /** A fully-valid updateSystemSettings input; override one key to exercise a single failing branch. */
    function ss_valid_system_input(array $overrides = []): array
    {
        return array_merge([
            'app_name' => 'Valid Name',
            'default_timezone' => 'Asia/Bangkok',
            'ticket_prefix' => 'MT',
            'business_start' => '08:00',
            'business_end' => '17:00',
        ], $overrides);
    }

    function ss_reject_system(array $overrides, string $message, string $ctx): void
    {
        $threw = false;
        try {
            ss_service()->updateSystemSettings(ss_admin(), ss_valid_system_input($overrides));
        } catch (DomainException $e) {
            $threw = true;
            assert_same($message, $e->getMessage(), $ctx);
        }
        assert_true($threw, "$ctx — must throw");
    }

    test('updateSystemSettings: every validation branch rejects before writing anything', function (): void {
        // snapshot the four keys this method writes; a reject must leave all of them untouched
        $keys = ['app_name', 'default_timezone', 'ticket_prefix', 'business_hours'];
        $snapshot = ss_snapshot($keys);
        try {
            ss_reject_system(['app_name' => ''], 'กรุณากรอกชื่อระบบ', 'empty app_name');
            ss_reject_system(['app_name' => '   '], 'กรุณากรอกชื่อระบบ', 'whitespace-only app_name');

            ss_reject_system(['default_timezone' => ''], 'Timezone ไม่ถูกต้อง', 'empty timezone');
            ss_reject_system(['default_timezone' => 'Mars/Phobos'], 'Timezone ไม่ถูกต้อง', 'non-existent timezone');

            $prefixMsg = 'Ticket prefix ต้องมี 2-12 ตัวอักษร และใช้ได้เฉพาะ A-Z, 0-9, ขีดกลาง หรือขีดล่าง';
            ss_reject_system(['ticket_prefix' => 'X'], $prefixMsg, 'ticket_prefix too short (<2)');
            ss_reject_system(['ticket_prefix' => 'TOOLONGPREFIX99'], $prefixMsg, 'ticket_prefix too long (>12)');
            ss_reject_system(['ticket_prefix' => 'A#B'], $prefixMsg, 'ticket_prefix has a forbidden char');

            $timeMsg = 'เวลาเริ่มและเวลาสิ้นสุดต้องอยู่ในรูปแบบ HH:MM';
            ss_reject_system(['business_start' => '8:00'], $timeMsg, 'business_start not HH:MM (single-digit hour)');
            ss_reject_system(['business_end' => '25:00'], $timeMsg, 'business_end has an out-of-range hour');

            ss_reject_system(['business_start' => '17:00', 'business_end' => '09:00'], 'เวลาเริ่มทำการต้องน้อยกว่าเวลาสิ้นสุด', 'start after end');
            ss_reject_system(['business_start' => '09:00', 'business_end' => '09:00'], 'เวลาเริ่มทำการต้องน้อยกว่าเวลาสิ้นสุด', 'start equal to end');

            // nothing was written by any reject — seed values still stand
            assert_same('Repair System', ss_get('app_name')['setting_value'] ?? null, 'app_name unchanged by rejects');
            assert_same('MT', ss_get('ticket_prefix')['setting_value'] ?? null, 'ticket_prefix unchanged by rejects');
        } finally {
            ss_restore($snapshot);
        }
    });

    test('updateSystemSettings: valid input persists app_name / timezone / prefix / business_hours', function (): void {
        $keys = ['app_name', 'default_timezone', 'ticket_prefix', 'business_hours'];
        $snapshot = ss_snapshot($keys);
        $newName = 'QA System ' . bin2hex(random_bytes(3));
        try {
            ss_bind_request();
            ss_service()->updateSystemSettings(ss_admin(), [
                'app_name' => $newName,
                'default_timezone' => 'Asia/Tokyo',
                'ticket_prefix' => 'qa', // lower-case: the service upper-cases it before validating/storing
                'business_start' => '09:15',
                'business_end' => '18:45',
            ]);

            assert_same($newName, ss_get('app_name')['setting_value'] ?? null, 'app_name written');
            assert_same('Asia/Tokyo', ss_get('default_timezone')['setting_value'] ?? null, 'timezone written');
            assert_same('QA', ss_get('ticket_prefix')['setting_value'] ?? null, 'ticket_prefix upper-cased then written');

            $hours = json_decode((string) (ss_get('business_hours')['setting_value'] ?? ''), true);
            assert_same(['start' => '09:15', 'end' => '18:45'], $hours, 'business_hours stored as {start,end} JSON');
            assert_same('json', ss_get('business_hours')['value_type'] ?? null, 'business_hours stored as json type');
        } finally {
            ss_restore($snapshot); // critical: report tests read timezone/business_hours
        }
    });

    // ── updateLogo (validation only; the physical save via move_uploaded_file is E2E) ──

    /** Real temp file with the given bytes (deleted by the caller). */
    function ss_tmp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sslogo_');
        file_put_contents($path, $bytes);
        return $path;
    }

    /** A $_FILES['logo'] entry; override individual keys to shape a specific branch. */
    function ss_logo_entry(array $override = []): array
    {
        return ['logo' => array_merge([
            'name' => 'logo.png',
            'type' => 'image/png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
        ], $override)];
    }

    test('updateLogo: a missing file is rejected', function (): void {
        $threw = false;
        try {
            ss_service()->updateLogo(ss_admin(), [], []); // no 'logo' key at all
        } catch (DomainException $e) {
            $threw = true;
            assert_same('กรุณาเลือกไฟล์โลโก้ที่จะอัปโหลด', $e->getMessage());
        }
        assert_true($threw, 'no file → rejected');

        $threw2 = false;
        try {
            ss_service()->updateLogo(ss_admin(), ss_logo_entry(['error' => UPLOAD_ERR_NO_FILE]), []);
        } catch (DomainException $e) {
            $threw2 = true;
            assert_same('กรุณาเลือกไฟล์โลโก้ที่จะอัปโหลด', $e->getMessage());
        }
        assert_true($threw2, 'UPLOAD_ERR_NO_FILE → rejected');
    });

    test('updateLogo: a failed upload (error != OK) is rejected', function (): void {
        $tmp = ss_tmp('anything');
        try {
            $threw = false;
            try {
                ss_service()->updateLogo(ss_admin(), ss_logo_entry(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_INI_SIZE]), []);
            } catch (DomainException $e) {
                $threw = true;
                assert_same('ไม่สามารถอ่านไฟล์โลโก้ได้ กรุณาลองใหม่', $e->getMessage());
            }
            assert_true($threw, 'a PHP upload error must be rejected');
        } finally {
            @unlink($tmp);
        }
    });

    test('updateLogo: a file larger than 1MB is rejected', function (): void {
        $tmp = ss_tmp('small on disk, but the reported size is what is checked');
        try {
            $threw = false;
            try {
                ss_service()->updateLogo(ss_admin(), ss_logo_entry(['tmp_name' => $tmp, 'size' => 1048577]), []);
            } catch (DomainException $e) {
                $threw = true;
                assert_same('ไฟล์โลโก้ต้องมีขนาดไม่เกิน 1MB', $e->getMessage());
            }
            assert_true($threw, 'a logo over 1MB must be rejected');
        } finally {
            @unlink($tmp);
        }
    });

    test('updateLogo(security): the MIME is sniffed from content, not trusted from the .png name', function (): void {
        // A PHP webshell (finfo → text/x-php) named "logo.png". The name lies; the sniff must win and reject it.
        $tmp = ss_tmp("<?php echo shell_exec(\$_GET[0]); ?>");
        try {
            $threw = false;
            try {
                ss_service()->updateLogo(ss_admin(), ss_logo_entry(['tmp_name' => $tmp, 'name' => 'logo.png', 'size' => 42]), []);
            } catch (DomainException $e) {
                $threw = true;
                assert_same('รองรับเฉพาะไฟล์ PNG, JPEG หรือ WebP', $e->getMessage());
            }
            assert_true($threw, 'MIME spoofing must be rejected: content is sniffed, extension is not trusted');
        } finally {
            @unlink($tmp);
        }
    });
}
