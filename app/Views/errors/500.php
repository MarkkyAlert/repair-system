<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-danger">Error</span>
        <h1 class="hero-title">เกิดข้อผิดพลาดภายในระบบ</h1>
        <p class="hero-text"><?= e($message ?? 'กรุณาลองใหม่อีกครั้งในภายหลัง') ?></p>
        <?= render_partial('partials/components/button', ['label' => 'กลับหน้าเข้าสู่ระบบ', 'href' => '/login', 'variant' => 'secondary']) ?>
    </div>
</section>
