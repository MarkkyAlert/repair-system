<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-danger">Error</span>
        <h1 class="hero-title">เกิดข้อผิดพลาดภายในระบบ</h1>
        <p class="hero-text"><?= e($message ?? 'กรุณาลองใหม่อีกครั้งในภายหลัง') ?></p>
        <?php if (($reference ?? '') !== ''): ?>
            <p class="hero-text" style="font-size:.85rem;opacity:.7;">รหัสอ้างอิง: <code><?= e((string) $reference) ?></code> — แจ้งรหัสนี้กับผู้ดูแลระบบเพื่อช่วยตรวจสอบได้เร็วขึ้น</p>
        <?php endif; ?>
        <?= render_partial('partials/components/button', ['label' => 'กลับหน้าเข้าสู่ระบบ', 'href' => '/login', 'variant' => 'secondary']) ?>
    </div>
</section>
