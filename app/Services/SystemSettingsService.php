<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * การตั้งค่าระบบสำหรับ admin: setting แบบอิสระ (freeform), ฟอร์ม system-settings หลัก
 * (ชื่อระบบ / timezone / ticket prefix / เวลาทำการ) และโลโก้องค์กร.
 * แยกออกมาจาก AdminService; ส่วนการอ่านข้อมูลของหน้า settings ยังอยู่ใน AdminService.
 */
class SystemSettingsService
{
    /**
     * Setting keys ที่ระบบจัดการผ่าน endpoint เฉพาะ ห้ามแก้ผ่าน /admin/settings (freeform)
     * เพื่อกัน admin เขียนทับโดยไม่ผ่าน validation ของ form หลัก
     */
    private const PROTECTED_SETTING_KEYS = [
        'app_logo_path',     // /admin/settings/logo
        'app_name',          // /admin/system-settings
        'app_tagline',       // /admin/system-settings
        'default_timezone',  // /admin/system-settings
        'ticket_prefix',     // /admin/system-settings
        'business_hours',    // /admin/system-settings
        'setup_completed',   // /setup — flag บอกสถานะระบบ (SetupController เขียนผ่าน repo ไม่ใช่ endpoint นี้)
    ];

    /**
     * Setting key prefixes ที่ระบบจัดการผ่าน endpoint เฉพาะ
     */
    private const PROTECTED_SETTING_PREFIXES = [
        'category_sla_',  // /admin/categories/*
    ];

    /** ความยาวสูงสุดของชื่อระบบ — เป็นแหล่งเดียวที่ใช้ทั้งตอน setup ครั้งแรกและตอนแก้ system-settings เพื่อไม่ให้
     *  ค่าเดียวกันถูกยอมรับในทางหนึ่งแต่ถูกปฏิเสธในอีกทางหนึ่ง. */
    public const APP_NAME_MAX_LENGTH = 100;

    public function __construct(
        private SettingsRepository $settings,
        private AuditLogger $audit,
        private PDO $db,
    ) {
    }

    /** ซ่อนการ์ด checklist เริ่มต้นใช้งานบน dashboard (flag รวมทั้งระบบ). */
    public function dismissSetupChecklist(array $viewer): void
    {
        assert_admin($viewer);
        $this->settings->upsert('admin_setup_checklist_dismissed', '1', 'bool', false, (int) ($viewer['id'] ?? 0));
    }

    public function updateSetting(array $viewer, array $input): void
    {
        assert_admin($viewer);
        $key = trim((string) ($input['setting_key'] ?? ''));
        if ($key === '') {
            throw new DomainException('กรุณาระบุ setting key');
        }

        if (in_array($key, self::PROTECTED_SETTING_KEYS, true)) {
            throw new DomainException('Setting key "' . $key . '" ถูกควบคุมโดยระบบ กรุณาแก้ผ่านฟอร์มเฉพาะ');
        }

        foreach (self::PROTECTED_SETTING_PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                throw new DomainException('Setting key prefix "' . $prefix . '" ถูกควบคุมโดยระบบ');
            }
        }

        $type = trim((string) ($input['value_type'] ?? 'string'));
        $value = trim((string) ($input['setting_value'] ?? ''));

        if (!in_array($type, ['string', 'int', 'bool', 'json'], true)) {
            throw new DomainException('ชนิดข้อมูลของ setting ไม่ถูกต้อง');
        }

        if ($type === 'json' && $value !== '') {
            json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new DomainException('ค่า setting แบบ JSON ไม่ถูกต้อง');
            }
        }

        $this->settings->upsert(
            $key,
            $value,
            $type,
            truthy_input($input['is_public'] ?? '0'),
            (int) ($viewer['id'] ?? 0)
        );
        $this->audit->record($viewer, 'setting.updated', 'system_setting', null, [
            'setting_key' => $key,
            'value_type' => $type,
            'is_public' => truthy_input($input['is_public'] ?? '0'),
        ]);
    }

    public function updateSystemSettings(array $viewer, array $input): void
    {
        assert_admin($viewer);

        $appName = trim((string) ($input['app_name'] ?? ''));
        $appTagline = trim((string) ($input['app_tagline'] ?? ''));
        $timezone = trim((string) ($input['default_timezone'] ?? ''));
        $ticketPrefix = strtoupper(trim((string) ($input['ticket_prefix'] ?? '')));
        $businessStart = trim((string) ($input['business_start'] ?? ''));
        $businessEnd = trim((string) ($input['business_end'] ?? ''));
        $updatedBy = (int) ($viewer['id'] ?? 0);

        if ($appName === '') {
            throw new DomainException('กรุณากรอกชื่อระบบ');
        }

        // ให้ตรงกับลิมิตของ setup ครั้งแรก — ชื่อเดียวกันต้องไม่ถูกปฏิเสธที่นั่นแต่ยอมรับที่นี่.
        if (mb_strlen($appName) > self::APP_NAME_MAX_LENGTH) {
            throw new DomainException('ชื่อระบบยาวเกินกำหนด (สูงสุด ' . self::APP_NAME_MAX_LENGTH . ' ตัวอักษร)');
        }

        // คำโปรยใต้ชื่อระบบเป็นตัวเลือก (เว้นว่างได้เพื่อซ่อน) แต่จำกัดความยาวกันข้อความล้น UI
        if (mb_strlen($appTagline) > 120) {
            throw new DomainException('คำโปรยใต้ชื่อระบบต้องยาวไม่เกิน 120 ตัวอักษร');
        }

        if ($timezone === '' || !in_array($timezone, timezone_identifiers_list(), true)) {
            throw new DomainException('Timezone ไม่ถูกต้อง');
        }

        if (!preg_match('/^[A-Z0-9_-]{2,12}$/', $ticketPrefix)) {
            throw new DomainException('Ticket prefix ต้องมี 2-12 ตัวอักษร และใช้ได้เฉพาะ A-Z, 0-9, ขีดกลาง หรือขีดล่าง');
        }

        if (!$this->isValidTime($businessStart) || !$this->isValidTime($businessEnd)) {
            throw new DomainException('เวลาเริ่มและเวลาสิ้นสุดต้องอยู่ในรูปแบบ HH:MM');
        }

        if ($businessStart >= $businessEnd) {
            throw new DomainException('เวลาเริ่มทำการต้องน้อยกว่าเวลาสิ้นสุด');
        }

        try {
            $this->db->beginTransaction();
            $this->settings->upsert('app_name', $appName, 'string', true, $updatedBy);
            $this->settings->upsert('app_tagline', $appTagline, 'string', true, $updatedBy);
            $this->settings->upsert('default_timezone', $timezone, 'string', false, $updatedBy);
            $this->settings->upsert('ticket_prefix', $ticketPrefix, 'string', false, $updatedBy);
            $this->settings->upsert('business_hours', json_encode([
                'start' => $businessStart,
                'end' => $businessEnd,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'json', false, $updatedBy);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        $this->audit->record($viewer, 'system_settings.updated', 'system_setting', null, [
            'app_name' => $appName,
            'app_tagline' => $appTagline,
            'default_timezone' => $timezone,
            'ticket_prefix' => $ticketPrefix,
            'business_hours' => [
                'start' => $businessStart,
                'end' => $businessEnd,
            ],
        ]);
    }

    public function updateLogo(array $viewer, array $files, array $input): void
    {
        assert_admin($viewer);

        $remove = truthy_input($input['remove_logo'] ?? '0');
        if ($remove) {
            // ทำ swap อ่านค่าปัจจุบัน → upsert → ลบไฟล์เดิม แบบทีละราย (serialize) (ดู withLogoPathLock) เพื่อไม่ให้
            // การเปลี่ยนโลโก้พร้อมกันทิ้งไฟล์ที่ไม่มีอะไรอ้างถึงไว้.
            $this->withLogoPathLock(function () use ($viewer): void {
                $currentLogoPath = $this->currentLogoFilePath();
                $this->settings->upsert('app_logo_path', '', 'string', true, (int) ($viewer['id'] ?? 0));
                $this->deleteLogoFile($currentLogoPath);
            });
            $this->audit->record($viewer, 'logo.removed', 'system_setting', null, ['setting_key' => 'app_logo_path']);
            return;
        }

        $file = $files['logo'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new DomainException('กรุณาเลือกไฟล์โลโก้ที่จะอัปโหลด');
        }

        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('ไม่สามารถอ่านไฟล์โลโก้ได้ กรุณาลองใหม่');
        }

        if ((int) ($file['size'] ?? 0) > 1048576) {
            throw new DomainException('ไฟล์โลโก้ต้องมีขนาดไม่เกิน 1MB');
        }

        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file((string) $file['tmp_name']);
        if (!isset($allowed[$mime])) {
            throw new DomainException('รองรับเฉพาะไฟล์ PNG, JPEG หรือ WebP');
        }

        $extension = $allowed[$mime];
        $relativeDirectory = $this->brandingRelativeDir();
        $absoluteDirectory = BASE_PATH . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('ไม่สามารถสร้างโฟลเดอร์เก็บโลโก้ได้');
        }

        $storedName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolutePath = $absoluteDirectory . '/' . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('ไม่สามารถบันทึกไฟล์โลโก้ได้');
        }

        $relativeStoredPath = $relativeDirectory . '/' . $storedName;
        // ไฟล์อยู่บนดิสก์แล้ว. ทำ อ่านค่าปัจจุบัน → upsert → ลบไฟล์เดิม แบบทีละราย (serialize) เพื่อไม่ให้ admin สองคนที่อัปโหลด
        // พร้อมกันทิ้งไฟล์กำพร้า (orphan) (คนที่รันทีหลังจะอ่าน path ของคนแรกว่าเป็น "ปัจจุบัน" แล้วลบมันทิ้ง).
        // try/catch รอบนอกจะล้างไฟล์ที่เพิ่งย้ายมา ถ้าการ "ขอ lock" (ACQUIRING THE LOCK) หรือ upsert ล้มเหลว — ไม่งั้น
        // ไฟล์ ≤1MB นั้นจะค้างอยู่โดยไม่มี setting อ้างถึง. $committed คุมกรณีที่ path ใหม่
        // ถูกบันทึกไปแล้ว: การลบไฟล์เดิมแบบ best-effort ทีหลังต้องไม่ไปลบไฟล์ที่กำลังถูกอ้างถึงเด็ดขาด.
        $committed = false;
        try {
            $this->withLogoPathLock(function () use ($relativeStoredPath, $viewer, &$committed): void {
                $currentLogoPath = $this->currentLogoFilePath();
                $this->settings->upsert('app_logo_path', $relativeStoredPath, 'string', true, (int) ($viewer['id'] ?? 0));
                $committed = true;
                $this->deleteLogoFile($currentLogoPath);
            });
        } catch (Throwable $exception) {
            if (!$committed) {
                $this->deleteLogoFile($absolutePath);
            }

            throw $exception;
        }

        $this->audit->record($viewer, 'logo.updated', 'system_setting', null, [
            'setting_key' => 'app_logo_path',
            'stored_path' => $relativeStoredPath,
            'mime' => $mime,
        ]);
    }

    /**
     * รัน swap ของ logo path (อ่านค่าปัจจุบัน → upsert → ลบไฟล์เดิม) ภายใต้ named lock ระดับ connection เพื่อให้ admin สอง
     * คนที่เปลี่ยนโลโก้พร้อมกันทำทีละคน (serialize) แทนที่ต่างคนต่างลบ path เดิมที่ใช้ร่วมกันจนทำให้
     * ไฟล์ที่เพิ่งเขียนของคนที่แพ้ไม่มีอะไรอ้างถึง (กลายเป็น orphan). ทำแบบเดียวกับรูปแบบ GET_LOCK ที่ใช้กับ
     * เลขรันนิ่ง. ไฟล์ที่อัปโหลดถูกย้ายลงดิสก์ "ก่อน" lock (ชื่อสุ่มไม่ซ้ำ ไม่แย่งกัน);
     * มีแค่การอ่าน/เขียน setting + การลบไฟล์เดิมเท่านั้นที่ต้องทำทีละราย.
     */
    /** โฟลเดอร์เก็บโลโก้ อ้างอิงจาก root ของแอป (กำหนดผ่าน config เพื่อให้ตอน deploy — หรือ test — เปลี่ยนปลายทางได้). */
    private function brandingRelativeDir(): string
    {
        return trim((string) config('uploads.branding_dir', 'storage/uploads/branding'), '/');
    }

    private function withLogoPathLock(callable $fn): void
    {
        $lockName = 'system-setting-app_logo_path';
        $lockStmt = $this->db->prepare('SELECT GET_LOCK(:name, 5)');
        $lockStmt->execute(['name' => $lockName]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            // มีการอัปเดตโลโก้พร้อมกันถือ lock อยู่ — เป็นเงื่อนไข "ลองใหม่" ที่คาดไว้ (EXPECTED) จึงเป็น
            // DomainException (แสดงผ่าน flash, ลองใหม่ได้) เหมือนกับ lock ของ setup.
            throw new DomainException('ระบบกำลังอัปเดตโลโก้ กรุณาลองอีกครั้ง');
        }

        try {
            $fn();
        } finally {
            try {
                $releaseStmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
                $releaseStmt->execute(['name' => $lockName]);
            } catch (Throwable) {
                // การปล่อย lock ที่ผูกกับ connection ต้องไม่บดบังผลลัพธ์ของการ swap
            }
        }
    }

    private function currentLogoFilePath(): ?string
    {
        $existing = $this->settings->getByKey('app_logo_path');
        $existingPath = trim((string) ($existing['setting_value'] ?? ''));
        if ($existingPath === '') {
            return null;
        }

        $relativePath = ltrim($existingPath, '/');
        $storageRoot = realpath(BASE_PATH . '/' . $this->brandingRelativeDir());
        $publicRoot = realpath(BASE_PATH . '/public/uploads/branding');
        $absoluteCandidates = [
            BASE_PATH . '/' . $relativePath,
            BASE_PATH . '/public/' . $relativePath,
        ];

        foreach ($absoluteCandidates as $absoluteCandidate) {
            $absoluteReal = realpath($absoluteCandidate);
            if ($absoluteReal === false || !is_file($absoluteReal)) {
                continue;
            }

            if (($storageRoot !== false && str_starts_with($absoluteReal, $storageRoot))
                || ($publicRoot !== false && str_starts_with($absoluteReal, $publicRoot))) {
                return $absoluteReal;
            }
        }

        return null;
    }

    private function deleteLogoFile(?string $path): void
    {
        // การ unlink (ลบไฟล์) ที่ล้มเหลวจะทิ้งไฟล์โลโก้กำพร้าไว้โดยไม่มีร่องรอย — log ไว้ (โดยไม่ทำให้การเซฟ settings ล้มเหลว)
        // เพื่อให้ทีมซัพพอร์ตตามไปลบทีหลังได้.
        if ($path !== null && is_file($path) && !@unlink($path)) {
            log_caught_exception(
                'settings.logo.cleanup',
                new \RuntimeException('logo file could not be deleted from disk'),
                ['file' => basename($path)]
            );
        }
    }

    private function isValidTime(string $value): bool
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return false;
        }

        return true;
    }
}
