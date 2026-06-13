<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-muted">404</span>
        <h1 class="hero-title">ไม่พบหน้าที่คุณต้องการ</h1>
        <p class="hero-text"><?= e($message ?? 'โปรดลองตรวจสอบ URL อีกครั้ง') ?></p>
        <?= render_partial('partials/components/button', ['label' => 'กลับหน้าเข้าสู่ระบบ', 'href' => '/login', 'variant' => 'primary']) ?>
    </div>
</section>
