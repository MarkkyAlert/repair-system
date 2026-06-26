<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-warning">403</span>
        <h1 class="hero-title">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p class="hero-text"><?= e($message ?? 'หน้านี้สงวนสำหรับบทบาทอื่น — โปรดติดต่อผู้ดูแลระบบหากคิดว่าควรเข้าได้') ?></p>
        <div class="error-actions">
            <?= render_partial('partials/components/button', ['label' => 'กลับหน้า Dashboard', 'href' => '/dashboard', 'variant' => 'primary']) ?>
            <?= render_partial('partials/components/button', ['label' => 'ออกจากระบบ', 'href' => '/logout', 'variant' => 'ghost']) ?>
        </div>
    </div>
</section>
