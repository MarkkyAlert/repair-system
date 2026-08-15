<section class="guest-panel">
    <div class="hero-card">
        <div class="hero-copy" style="text-align:center">
            <span class="pill">รับเรื่องแล้ว</span>
            <h1 class="hero-title">ขอบคุณที่แจ้งปัญหา</h1>
            <p class="hero-text">ทีมงานจะตรวจสอบและติดต่อกลับโดยเร็ว</p>

            <div style="margin-top:2rem;padding:2rem;border-radius:14px;background:linear-gradient(135deg,var(--indigo-50),rgba(99,102,241,.08));text-align:center">
                <p class="helper-text">เลขที่อ้างอิงของคุณ</p>
                <p id="guest-reference" style="font-size:1.5rem;font-weight:800;color:var(--indigo-600);margin:.5rem 0;font-family:var(--font-mono,monospace)"><?= e($requestNo) ?></p>
                <p class="helper-text">เก็บเลขนี้ไว้สำหรับติดตามผล</p>
                <?php // ปุ่มซ่อนไว้ก่อน แล้ว copy-text.js เป็นคนเปิด — ถ้าเบราว์เซอร์ปิด JS จะได้ไม่เห็นปุ่มที่กดแล้วเงียบ ?>
                <button type="button" class="btn btn-secondary btn-sm" style="display:none;margin-top:.75rem" hidden
                        data-copy-source="#guest-reference"
                        data-copy-status="#guest-reference-copy-status"
                        data-copy-done="คัดลอกแล้ว"
                        data-copy-failed="คัดลอกไม่ได้ กรุณาคัดลอกเอง">
                    <?= lucide('copy', 'button-icon') ?><span>คัดลอกเลขที่อ้างอิง</span>
                </button>
                <p id="guest-reference-copy-status" class="sr-only" role="status" aria-live="polite"></p>
            </div>

            <div style="margin-top:1.5rem">
                <?= render_partial('partials/components/button', ['label' => 'ติดตามสถานะคำขอ', 'href' => '/track?ref=' . rawurlencode((string) $requestNo), 'variant' => 'primary', 'icon' => 'search', 'iconPosition' => 'right']) ?>
            </div>
        </div>
    </div>
</section>
<script src="<?= e(asset('js/copy-text.js')) ?>" defer></script>
