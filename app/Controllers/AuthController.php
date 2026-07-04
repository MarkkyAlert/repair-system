<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Core\Response;
use App\Repositories\NotificationPreferenceRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\RememberMeService;
use DomainException;
use RuntimeException;

class AuthController
{
    use HandlesFormSubmission;

    public function __construct(
        private AuthService $service,
        private NotificationPreferenceRepository $preferences,
        private UserRepository $users,
        private RememberMeService $rememberMe,
    ) {
    }

    public function home(): void
    {
        if (auth()->check()) {
            Response::redirect('/dashboard');
        }

        Response::redirect('/login');
    }

    public function showLogin(): void
    {
        GuestMiddleware::handle();

        Response::view('auth/login', [
            'title' => 'เข้าสู่ระบบ',
            'pageHeading' => 'เข้าสู่ระบบเพื่อใช้งานระบบแจ้งซ่อม',
            'returnTo' => intended_path(),
            'oldInput' => pull_old_input(),
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ], 'guest');
    }

    public function login(): void
    {
        GuestMiddleware::handle();

        $returnTo = sanitize_return_path((string) ($_POST['return_to'] ?? '/dashboard'));
        $login = trim((string) ($_POST['login'] ?? ''));

        try {
            csrf_validate();
            clear_old_input();

            $this->service->attemptLogin(
                $login,
                (string) ($_POST['password'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                truthy_input($_POST['remember'] ?? '0')
            );

            flash('success', 'เข้าสู่ระบบเรียบร้อยแล้ว');
            Response::redirect($returnTo);
        } catch (DomainException|RuntimeException $exception) {
            with_old_input(['login' => $login]);
            flash('error', $exception->getMessage());
            Response::redirect('/login?return=' . rawurlencode($returnTo));
        }
    }

    public function logout(): void
    {
        $this->handleUpdate(
            fn (array $viewer) => $this->service->logout(),
            'ออกจากระบบเรียบร้อยแล้ว',
            '/login'
        );
    }

    public function showForgotPassword(): void
    {
        GuestMiddleware::handle();

        Response::view('auth/forgot-password', [
            'title' => 'ลืมรหัสผ่าน',
            'pageHeading' => 'ลืมรหัสผ่าน',
            'oldInput' => pull_old_input(),
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ], 'guest');
    }

    public function sendResetLink(): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_POST['email'] ?? ''));

        try {
            csrf_validate();
            clear_old_input();

            $this->service->createPasswordReset($email);
            flash('success', 'หากอีเมลนี้มีอยู่ในระบบ ระบบได้สร้างคำขอรีเซ็ตรหัสผ่านให้แล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input(['email' => $email]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/forgot-password');
    }

    public function showResetPassword(?string $token = null): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_GET['email'] ?? ''));
        $token = trim((string) ($token ?? $_GET['token'] ?? ''));

        if ($email === '' || $token === '') {
            flash('error', 'ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้อง');
            Response::redirect('/forgot-password');
        }

        Response::view('auth/reset-password', [
            'title' => 'ตั้งรหัสผ่านใหม่',
            'pageHeading' => 'ตั้งรหัสผ่านใหม่',
            'email' => $email,
            'token' => $token,
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ], 'guest');
    }

    public function resetPassword(?string $token = null): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_POST['email'] ?? ''));
        $token = trim((string) ($token ?? $_POST['token'] ?? ''));

        try {
            csrf_validate();

            $this->service->resetPassword(
                $email,
                $token,
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );

            flash('success', 'ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว กรุณาเข้าสู่ระบบอีกครั้ง');
            Response::redirect('/login');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
            Response::redirect('/reset-password/' . rawurlencode($token) . '?email=' . rawurlencode($email));
        }
    }

    public function showChangePassword(): void
    {
        AuthMiddleware::handle();

        Response::view('auth/change-password', [
            'title' => 'เปลี่ยนรหัสผ่าน',
            'pageHeading' => 'เปลี่ยนรหัสผ่าน',
            'currentUser' => auth()->user() ?? [],
        ]);
    }

    public function changePassword(): void
    {
        AuthMiddleware::handle();

        try {
            csrf_validate();
            $this->service->changePassword(
                auth()->user() ?? [],
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirmation'] ?? '')
            );
            flash('success', 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/change-password');
    }

    public function showProfile(): void
    {
        AuthMiddleware::handle();

        $viewer = auth()->user() ?? [];
        $oldInput = pull_old_input();
        $userId = (int) ($viewer['id'] ?? 0);
        $fresh = $userId > 0 ? ($this->users->findById($userId) ?? []) : [];

        $passwordChangedAt = (string) ($fresh['password_changed_at'] ?? '');
        $lastLoginAt = (string) ($fresh['last_login_at'] ?? '');
        $createdAt = (string) ($fresh['created_at'] ?? '');
        $hasRememberToken = ((string) ($fresh['remember_token'] ?? '')) !== '';

        $passwordAgeDays = null;
        if ($passwordChangedAt !== '') {
            $ts = strtotime($passwordChangedAt);
            if ($ts !== false) {
                $passwordAgeDays = (int) floor((time() - $ts) / 86400);
            }
        }

        Response::view('auth/profile', [
            'title' => 'ข้อมูลบัญชี',
            'pageHeading' => 'ข้อมูลบัญชีของฉัน',
            'currentUser' => $viewer,
            'profile' => [
                'full_name' => (string) ($oldInput['full_name'] ?? ($viewer['full_name'] ?? '')),
                'email' => (string) ($oldInput['email'] ?? ($viewer['email'] ?? '')),
                'phone' => (string) ($oldInput['phone'] ?? ($viewer['phone'] ?? '')),
                'username' => (string) ($viewer['username'] ?? ''),
                'role' => (string) ($viewer['role'] ?? 'guest'),
                'created_at' => $createdAt,
                'last_login_at' => $lastLoginAt,
            ],
            'security' => [
                'password_changed_at' => $passwordChangedAt,
                'password_age_days' => $passwordAgeDays,
                'has_remember_token' => $hasRememberToken,
            ],
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ]);
    }

    public function revokeRememberMe(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $userId = (int) ($viewer['id'] ?? 0);

        try {
            csrf_validate();
            if ($userId <= 0) {
                throw new DomainException('ไม่พบบัญชีผู้ใช้งาน');
            }
            $this->users->updateRememberToken($userId, null);
            $this->rememberMe->clearCurrent();
            flash('success', 'ยกเลิกการจดจำการเข้าระบบทุกอุปกรณ์เรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/profile');
    }

    public function updateProfile(): void
    {
        AuthMiddleware::handle();

        try {
            csrf_validate();
            $this->service->updateProfile(auth()->user() ?? [], $_POST);
            clear_old_input();
            flash('success', 'อัปเดตข้อมูลบัญชีเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            with_old_input([
                'full_name' => (string) ($_POST['full_name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
            ]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/profile');
    }

    public function showNotificationPreferences(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $userId = (int) ($viewer['id'] ?? 0);
        $matrix = $this->preferences->getMatrix($userId);

        $rendered = [];
        foreach (NotificationService::NOTIFICATION_TYPES as $type => $label) {
            $rendered[$type] = [
                'label' => $label,
                'hint' => NotificationService::NOTIFICATION_TYPE_HINTS[$type] ?? '',
                'off_impact' => NotificationService::NOTIFICATION_TYPE_OFF_IMPACT[$type] ?? '',
                'email' => $matrix[$type]['email'] ?? true,
                'in_app' => $matrix[$type]['in_app'] ?? true,
            ];
        }

        Response::view('auth/notification-preferences', [
            'title' => 'ตั้งค่าการแจ้งเตือน',
            'pageHeading' => 'ตั้งค่าการแจ้งเตือน',
            'currentUser' => $viewer,
            'preferences' => $rendered,
            'errorMessage' => flash_message('error'),
            'successMessage' => flash_message('success'),
        ]);
    }

    public function updateNotificationPreferences(): void
    {
        AuthMiddleware::handle();
        $viewer = auth()->user() ?? [];
        $userId = (int) ($viewer['id'] ?? 0);

        try {
            csrf_validate();
            $input = (array) ($_POST['pref'] ?? []);
            $matrix = [];
            foreach (NotificationService::NOTIFICATION_TYPES as $type => $_label) {
                $matrix[$type] = [
                    'email' => isset($input[$type]['email']),
                    'in_app' => isset($input[$type]['in_app']),
                ];
            }
            $this->preferences->upsertMatrix($userId, $matrix);
            flash('success', 'บันทึกการตั้งค่าการแจ้งเตือนเรียบร้อยแล้ว');
        } catch (DomainException|RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/profile/notifications');
    }
}
