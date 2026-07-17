<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-muted">404</span>
        <h1 class="hero-title">ไม่พบหน้าที่คุณต้องการ</h1>
        <p class="hero-text"><?= e($message ?? 'โปรดลองตรวจสอบ URL อีกครั้ง') ?></p>
        <?php $errAuthed = \App\Core\AuthManager::checkSession(); // DB-free session probe (no repository resolve) so this renders during an outage too — a signed-in user who hits a dead link goes home, not to /login (ux-review-2 F8, ux-review-3 F1) ?>
        <?= render_partial('partials/components/button', [
            'label' => $errAuthed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ',
            'href' => $errAuthed ? '/dashboard' : '/login',
            'variant' => 'primary',
        ]) ?>
    </div>
</section>
