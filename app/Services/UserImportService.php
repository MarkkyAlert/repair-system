<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\UserRepository;
use DomainException;
use Throwable;

class UserImportService
{
    public const CSV_COLUMNS = [
        'username', 'email', 'full_name', 'role', 'department_code', 'phone', 'password',
    ];

    public const ALLOWED_ROLES = ['requester', 'manager', 'technician', 'admin'];

    public function __construct(
        private AdminRepository $admin,
        private UserRepository $users,
        private AuthService $auth,
    ) {
    }

    public function parseUploadedFile(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new DomainException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาเลือกไฟล์ CSV และลองอีกครั้ง');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new DomainException('รองรับเฉพาะไฟล์ .csv เท่านั้น');
        }

        if ((int) ($file['size'] ?? 0) > 1 * 1024 * 1024) {
            throw new DomainException('ไฟล์มีขนาดเกิน 1MB');
        }

        $handle = fopen((string) $file['tmp_name'], 'r');
        if ($handle === false) {
            throw new DomainException('ไม่สามารถเปิดไฟล์ที่อัปโหลดได้');
        }

        $rows = [];
        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === null) {
                throw new DomainException('ไฟล์ CSV ว่างเปล่า');
            }

            $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);
            $missing = array_diff(self::CSV_COLUMNS, $header);
            if ($missing !== []) {
                throw new DomainException('ไฟล์ CSV ไม่ครบ column: ' . implode(', ', $missing));
            }

            $lineNo = 1;
            while (($row = fgetcsv($handle)) !== false) {
                $lineNo++;
                if (count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                $assoc = ['_line' => $lineNo];
                foreach ($header as $idx => $colName) {
                    if (in_array($colName, self::CSV_COLUMNS, true)) {
                        $assoc[$colName] = isset($row[$idx]) ? trim((string) $row[$idx]) : '';
                    }
                }
                $rows[] = $assoc;
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new DomainException('ไม่พบข้อมูลใน CSV');
        }

        if (count($rows) > 200) {
            throw new DomainException('นำเข้าได้ครั้งละไม่เกิน 200 รายการ');
        }

        return $rows;
    }

    public function validateRows(array $rows): array
    {
        $departments = $this->admin->getDepartments();
        $deptsByCode = [];
        foreach ($departments as $dept) {
            $code = strtoupper(trim((string) ($dept['code'] ?? '')));
            if ($code !== '') {
                $deptsByCode[$code] = (int) ($dept['id'] ?? 0);
            }
        }

        $valid = [];
        $invalid = [];
        $seenUsernames = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $errors = [];
            $username = strtolower(trim((string) ($row['username'] ?? '')));
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            $fullName = trim((string) ($row['full_name'] ?? ''));
            $role = strtolower(trim((string) ($row['role'] ?? 'requester'))) ?: 'requester';
            $deptCode = strtoupper(trim((string) ($row['department_code'] ?? '')));
            $phone = trim((string) ($row['phone'] ?? ''));
            $password = (string) ($row['password'] ?? '');

            if ($username === '' || $email === '' || $fullName === '') {
                $errors[] = 'username, email, full_name จำเป็นต้องมี';
            }
            if ($username !== '' && !preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
                $errors[] = 'username ต้องมี 3-50 ตัว และใช้ a-z, 0-9, ., -, _';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email format ไม่ถูกต้อง';
            }
            if (!in_array($role, self::ALLOWED_ROLES, true)) {
                $errors[] = 'role ต้องเป็น: ' . implode(', ', self::ALLOWED_ROLES);
            }
            if ($phone !== '' && !preg_match('/^[0-9+\-() .]{4,30}$/', $phone)) {
                $errors[] = 'phone format ไม่ถูกต้อง';
            }
            if ($password !== '' && strlen($password) < 8) {
                $errors[] = 'password ต้องมีอย่างน้อย 8 ตัวอักษร (เว้นว่างเพื่อ auto-generate)';
            }
            if ($username !== '' && isset($seenUsernames[$username])) {
                $errors[] = 'username ซ้ำกับแถวอื่นในไฟล์';
            }
            if ($email !== '' && isset($seenEmails[$email])) {
                $errors[] = 'email ซ้ำกับแถวอื่นในไฟล์';
            }
            if ($username !== '' && $this->users->findByLogin($username) !== null) {
                $errors[] = 'username มีอยู่ในระบบแล้ว';
            }
            if ($email !== '' && $this->users->findByEmail($email) !== null) {
                $errors[] = 'email มีอยู่ในระบบแล้ว';
            }

            $departmentId = null;
            if ($deptCode !== '') {
                $departmentId = $deptsByCode[$deptCode] ?? null;
                if ($departmentId === null) {
                    $errors[] = 'department_code "' . $deptCode . '" ไม่พบในระบบ';
                }
            }

            $entry = [
                'line' => (int) ($row['_line'] ?? 0),
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'errors' => $errors,
            ];

            if ($errors !== []) {
                $invalid[] = $entry;
                continue;
            }

            $seenUsernames[$username] = true;
            $seenEmails[$email] = true;
            $valid[] = [
                'line' => (int) ($row['_line'] ?? 0),
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'role' => $role,
                'department_id' => $departmentId,
                'phone' => $phone,
                'password' => $password,
                'auto_password' => $password === '',
            ];
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'total' => count($rows),
        ];
    }

    public function executeImport(array $validRows): array
    {
        $imported = 0;
        $skipped = [];
        $sentResetEmails = 0;

        foreach ($validRows as $row) {
            try {
                $password = (string) ($row['password'] ?? '');
                if ($password === '') {
                    $password = bin2hex(random_bytes(8));
                }

                $payload = [
                    'username' => (string) $row['username'],
                    'full_name' => (string) $row['full_name'],
                    'email' => (string) $row['email'],
                    'phone' => (string) ($row['phone'] ?? ''),
                    'role' => (string) $row['role'],
                    'department_id' => $row['department_id'] ?? null,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'is_active' => true,
                ];
                $this->admin->createUser($payload);
                $imported++;

                if (!empty($row['auto_password'])) {
                    try {
                        $this->auth->createPasswordReset((string) $row['email']);
                        $sentResetEmails++;
                    } catch (Throwable) {
                        // Reset email enqueue failure should not break import
                    }
                }
            } catch (Throwable $exception) {
                $skipped[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                    'reason' => $this->isDuplicateKey($exception)
                        ? 'username หรือ email ซ้ำกับข้อมูลที่มีอยู่'
                        : 'เกิดข้อผิดพลาดในการบันทึก',
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'reset_emails_queued' => $sentResetEmails,
        ];
    }

    private function isDuplicateKey(Throwable $exception): bool
    {
        if (!$exception instanceof \PDOException) {
            return false;
        }
        $code = (string) $exception->getCode();
        $message = $exception->getMessage();
        return $code === '23000' || str_contains($message, 'Duplicate entry') || str_contains($message, '1062');
    }
}
