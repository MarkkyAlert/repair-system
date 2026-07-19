<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-muted">404</span>
        <h1 class="hero-title">ไม่พบหน้าที่คุณต้องการ</h1>
        <p class="hero-text"><?= e($message ?? 'โปรดลองตรวจสอบ URL อีกครั้ง') ?></p>
        <?php $errAuthed = \App\Core\AuthManager::checkSession(); // ตรวจ session แบบไม่แตะ DB (ไม่ resolve repository) เพื่อให้หน้านี้แสดงได้แม้ตอนระบบล่ม — ผู้ใช้ที่ล็อกอินอยู่แล้วเจอลิงก์เสียจะถูกพากลับหน้าแรก ไม่ใช่ไป /login ?>
        <?= render_partial('partials/components/button', [
            'label' => $errAuthed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ',
            'href' => $errAuthed ? '/dashboard' : '/login',
            'variant' => 'primary',
        ]) ?>
    </div>
</section>
