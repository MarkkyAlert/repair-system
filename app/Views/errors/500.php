<section class="error-shell">
    <div class="error-card">
        <span class="pill pill-danger">Error</span>
        <h1 class="hero-title">เกิดข้อผิดพลาดภายในระบบ</h1>
        <p class="hero-text"><?= e($message ?? 'กรุณาลองใหม่อีกครั้งในภายหลัง') ?></p>
        <?php if (($reference ?? '') !== ''): ?>
            <p class="hero-text error-reference">รหัสอ้างอิง: <code><?= e((string) $reference) ?></code> — แจ้งรหัสนี้กับผู้ดูแลระบบเพื่อช่วยตรวจสอบได้เร็วขึ้น</p>
        <?php endif; ?>
        <?php $errAuthed = \App\Core\AuthManager::checkSession(); // ตรวจ session แบบไม่แตะ DB (ไม่ resolve repository) — หน้า 500 ต้องแสดงได้แม้ตอน DB ล่ม ?>
        <?= render_partial('partials/components/button', [
            'label' => $errAuthed ? 'กลับแดชบอร์ด' : 'กลับหน้าเข้าสู่ระบบ',
            'href' => $errAuthed ? '/dashboard' : '/login',
            'variant' => 'secondary',
        ]) ?>
    </div>
</section>
