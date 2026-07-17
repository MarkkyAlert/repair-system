<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-danger">Error</span>
        <h1 class="hero-title">เกิดข้อผิดพลาดภายในระบบ</h1>
        <p class="hero-text"><?= e($message ?? 'กรุณาลองใหม่อีกครั้งในภายหลัง') ?></p>
        <?php if (($reference ?? '') !== ''): ?>
            <p class="hero-text" style="font-size:.85rem;opacity:.7;">รหัสอ้างอิง: <code><?= e((string) $reference) ?></code> — แจ้งรหัสนี้กับผู้ดูแลระบบเพื่อช่วยตรวจสอบได้เร็วขึ้น</p>
        <?php endif; ?>
        <?php $errAuthed = auth()->check(); // signed-in users recover to the dashboard, not the login page (ux-review-2 F8) ?>
        <?= render_partial('partials/components/button', [
            'label' => $errAuthed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ',
            'href' => $errAuthed ? '/dashboard' : '/login',
            'variant' => 'secondary',
        ]) ?>
    </div>
</section>
