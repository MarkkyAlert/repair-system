<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminRepository;
use App\Repositories\UserRepository;
use DomainException;
use Throwable;

class UserImportService
{
    use ParsesCsvUpload;

    public const CSV_COLUMNS = [
        'username', 'email', 'full_name', 'role', 'department_code', 'phone', 'password',
    ];

    public function __construct(
        private AdminRepository $admin,
        private UserRepository $users,
        private AuthService $auth,
    ) {
    }

    public function parseUploadedFile(array $file): array
    {
        return $this->parseCsvUpload($file, self::CSV_COLUMNS, 1 * 1024 * 1024, 200);
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

        // Batch existence lookup ครั้งเดียว (แทน findByLogin/findByEmail ต่อแถว = 2N queries)
        $existing = $this->users->existingLoginsAndEmails(
            array_map(static fn (array $r): string => strtolower(trim((string) ($r['username'] ?? ''))), $rows),
            array_map(static fn (array $r): string => strtolower(trim((string) ($r['email'] ?? ''))), $rows)
        );

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
            if ($email !== '' && !is_valid_email($email)) {
                $errors[] = 'email format ไม่ถูกต้อง';
            }
            if (!in_array($role, valid_roles(), true)) {
                $errors[] = 'role ต้องเป็น: ' . implode(', ', valid_roles());
            }
            if ($phone !== '' && !valid_phone_format($phone)) {
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
            if ($username !== '' && isset($existing['logins'][$username])) {
                $errors[] = 'username มีอยู่ในระบบแล้ว';
            }
            if ($email !== '' && isset($existing['emails'][$email])) {
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
        $resetFailures = [];

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
                        // The user is created active with a random password; a reset-email failure must not
                        // break the import, but it also must not be swallowed silently — record who did not
                        // receive a reset so the admin can reset them manually.
                        $resetFailures[] = [
                            'line' => (int) ($row['line'] ?? 0),
                            'username' => (string) ($row['username'] ?? ''),
                            'email' => (string) ($row['email'] ?? ''),
                        ];
                    }
                }
            } catch (Throwable $exception) {
                $skipped[] = [
                    'line' => (int) ($row['line'] ?? 0),
                    'username' => (string) ($row['username'] ?? ''),
                    'reason' => is_duplicate_key_error($exception)
                        ? 'username หรือ email ซ้ำกับข้อมูลที่มีอยู่'
                        : 'เกิดข้อผิดพลาดในการบันทึก',
                ];
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'reset_emails_queued' => $sentResetEmails,
            'reset_failures' => $resetFailures,
        ];
    }
}
