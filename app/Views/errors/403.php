<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-warning">403</span>
        <h1 class="hero-title">คุณไม่มีสิทธิ์เข้าถึงหน้านี้</h1>
        <p class="hero-text"><?= e($message ?? 'หน้านี้สงวนสำหรับบทบาทอื่น — โปรดติดต่อผู้ดูแลระบบหากคิดว่าควรเข้าได้') ?></p>
        <div class="error-actions">
            <?= render_partial('partials/components/button', ['label' => 'กลับหน้า Dashboard', 'href' => '/dashboard', 'variant' => 'primary']) ?>
            <?php // logout รับเฉพาะ POST (พร้อม CSRF); ลิงก์แบบ GET ไป /logout จะกลายเป็น 404 — ต้อง submit ผ่าน form จริงแทน ?>
            <form method="post" action="<?= e(url('/logout')) ?>" style="display:contents">
                <?= csrf_field() ?>
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ออกจากระบบ', 'variant' => 'ghost']) ?>
            </form>
        </div>
    </div>
</section>
