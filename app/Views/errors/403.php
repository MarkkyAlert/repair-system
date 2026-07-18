<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-warning">403</span>
        <h1 class="hero-title">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p class="hero-text"><?= e($message ?? 'หน้านี้สงวนสำหรับบทบาทอื่น — โปรดติดต่อผู้ดูแลระบบหากคิดว่าควรเข้าได้') ?></p>
        <div class="error-actions">
            <?= render_partial('partials/components/button', ['label' => 'กลับหน้า Dashboard', 'href' => '/dashboard', 'variant' => 'primary']) ?>
            <?php // logout is POST-only (+CSRF); a GET link to /logout 404s. Submit a real form instead. (ux-review-7 F1) ?>
            <form method="post" action="<?= e(url('/logout')) ?>" style="display:contents">
                <?= csrf_field() ?>
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ออกจากระบบ', 'variant' => 'ghost']) ?>
            </form>
        </div>
    </div>
</section>
