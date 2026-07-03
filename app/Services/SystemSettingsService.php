<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
use DomainException;
use PDO;
use Throwable;

/**
 * Admin system configuration: freeform settings, the core system-settings form
 * (app name / timezone / ticket prefix / business hours) and the organisation logo.
 * Extracted from AdminService; reads for the settings page stay in AdminService.
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
        'default_timezone',  // /admin/system-settings
        'ticket_prefix',     // /admin/system-settings
        'business_hours',    // /admin/system-settings
    ];

    /**
     * Setting key prefixes ที่ระบบจัดการผ่าน endpoint เฉพาะ
     */
    private const PROTECTED_SETTING_PREFIXES = [
        'category_sla_',  // /admin/categories/*
    ];

    public function __construct(
        private SettingsRepository $settings,
        private AuditLogger $audit,
        private PDO $db,
    ) {
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
            in_array((string) ($input['is_public'] ?? '0'), ['1', 'true', 'on'], true),
            (int) ($viewer['id'] ?? 0)
        );
        $this->audit->record($viewer, 'setting.updated', 'system_setting', null, [
            'setting_key' => $key,
            'value_type' => $type,
            'is_public' => in_array((string) ($input['is_public'] ?? '0'), ['1', 'true', 'on'], true),
        ]);
    }

    public function updateSystemSettings(array $viewer, array $input): void
    {
        assert_admin($viewer);

        $appName = trim((string) ($input['app_name'] ?? ''));
        $timezone = trim((string) ($input['default_timezone'] ?? ''));
        $ticketPrefix = strtoupper(trim((string) ($input['ticket_prefix'] ?? '')));
        $businessStart = trim((string) ($input['business_start'] ?? ''));
        $businessEnd = trim((string) ($input['business_end'] ?? ''));
        $updatedBy = (int) ($viewer['id'] ?? 0);

        if ($appName === '') {
            throw new DomainException('กรุณากรอกชื่อระบบ');
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

        $remove = in_array((string) ($input['remove_logo'] ?? '0'), ['1', 'true', 'on'], true);
        if ($remove) {
            $currentLogoPath = $this->currentLogoFilePath();
            $this->settings->upsert('app_logo_path', '', 'string', true, (int) ($viewer['id'] ?? 0));
            $this->deleteLogoFile($currentLogoPath);
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
        $relativeDirectory = 'storage/uploads/branding';
        $absoluteDirectory = BASE_PATH . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
            throw new \RuntimeException('ไม่สามารถสร้างโฟลเดอร์เก็บโลโก้ได้');
        }

        $storedName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $extension;
        $absolutePath = $absoluteDirectory . '/' . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('ไม่สามารถบันทึกไฟล์โลโก้ได้');
        }

        $currentLogoPath = $this->currentLogoFilePath();
        $relativeStoredPath = $relativeDirectory . '/' . $storedName;
        try {
            $this->settings->upsert('app_logo_path', $relativeStoredPath, 'string', true, (int) ($viewer['id'] ?? 0));
        } catch (Throwable $exception) {
            $this->deleteLogoFile($absolutePath);
            throw $exception;
        }

        $this->deleteLogoFile($currentLogoPath);
        $this->audit->record($viewer, 'logo.updated', 'system_setting', null, [
            'setting_key' => 'app_logo_path',
            'stored_path' => $relativeStoredPath,
            'mime' => $mime,
        ]);
    }

    private function currentLogoFilePath(): ?string
    {
        $existing = $this->settings->getByKey('app_logo_path');
        $existingPath = trim((string) ($existing['setting_value'] ?? ''));
        if ($existingPath === '') {
            return null;
        }

        $relativePath = ltrim($existingPath, '/');
        $storageRoot = realpath(BASE_PATH . '/storage/uploads/branding');
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
        if ($path !== null && is_file($path)) {
            @unlink($path);
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
