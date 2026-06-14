<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use App\Core\Response;
use App\Services\AuthService;
use DomainException;
use RuntimeException;

class AuthController
{
    public function __construct(private AuthService $service)
    {
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
            'debugResetLink' => flash_message('debug_reset_link'),
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
                (string) ($_SERVER['REMOTE_ADDR'] ?? '')
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
        AuthMiddleware::handle();

        try {
            csrf_validate();
            $this->service->logout();
            flash('success', 'ออกจากระบบเรียบร้อยแล้ว');
        } catch (RuntimeException $exception) {
            flash('error', $exception->getMessage());
        }

        Response::redirect('/login');
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
            'debugResetLink' => flash_message('debug_reset_link'),
        ], 'guest');
    }

    public function sendResetLink(): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_POST['email'] ?? ''));

        try {
            csrf_validate();
            clear_old_input();

            $resetLink = $this->service->createPasswordReset($email);
            flash('success', 'หากอีเมลนี้มีอยู่ในระบบ ระบบได้สร้างคำขอรีเซ็ตรหัสผ่านให้แล้ว');

            if ((bool) config('app.debug', false) && $resetLink !== null) {
                flash('debug_reset_link', $resetLink);
            }
        } catch (DomainException|RuntimeException $exception) {
            with_old_input(['email' => $email]);
            flash('error', $exception->getMessage());
        }

        Response::redirect('/forgot-password');
    }

    public function showResetPassword(): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_GET['email'] ?? ''));
        $token = trim((string) ($_GET['token'] ?? ''));

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

    public function resetPassword(): void
    {
        GuestMiddleware::handle();

        $email = trim((string) ($_POST['email'] ?? ''));
        $token = trim((string) ($_POST['token'] ?? ''));

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
            Response::redirect('/reset-password?email=' . rawurlencode($email) . '&token=' . rawurlencode($token));
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
}
