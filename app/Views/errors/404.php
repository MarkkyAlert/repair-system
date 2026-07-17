<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-muted">404</span>
        <h1 class="hero-title">ไม่พบหน้าที่คุณต้องการ</h1>
        <p class="hero-text"><?= e($message ?? 'โปรดลองตรวจสอบ URL อีกครั้ง') ?></p>
        <?php $errAuthed = auth()->check(); // a signed-in user who hits a dead link isn't logged out — send them home, not to /login (ux-review-2 F8) ?>
        <?= render_partial('partials/components/button', [
            'label' => $errAuthed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ',
            'href' => $errAuthed ? '/dashboard' : '/login',
            'variant' => 'primary',
        ]) ?>
    </div>
</section>
