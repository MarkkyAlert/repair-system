<?php
$old = is_array($oldInput ?? null) ? $oldInput : [];
$oldTitle = (string) ($old['title'] ?? '');
$oldMessage = (string) ($old['message'] ?? '');
$oldRoleFilter = (string) ($old['role_filter'] ?? '');
$roleOptions = [
    '' => 'ผู้ใช้งานทุกบทบาท',
    'requester' => 'เฉพาะ Requester',
    'manager' => 'เฉพาะ Manager',
    'technician' => 'เฉพาะ Technician',
    'admin' => 'เฉพาะ Admin',
];
$counts = is_array($recipientCounts ?? null) ? $recipientCounts : [];
$initialCount = (int) ($counts[$oldRoleFilter] ?? 0);
?>
<section class="stack-lg broadcast-page">
    <h1 class="sr-only">ส่งประกาศถึงผู้ใช้</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => 'ประกาศจากผู้ดูแลระบบ',
        'description' => 'ประกาศข้อความถึงผู้ใช้ทั้งหมดในระบบ',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าตั้งค่า',
            'variant' => 'secondary',
            'href' => '/admin',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert auth-alert-danger" role="alert">
            <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
            <p><?= e((string) $errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="auth-alert auth-alert-success" role="status">
            <span class="auth-alert-icon"><?= lucide('check-circle', 'h-4 w-4') ?></span>
            <p><?= e((string) $successMessage) ?></p>
        </div>
    <?php endif; ?>

    <section class="panel-card panel-narrow stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">เนื้อหาประกาศ</h2>
            </div>
            <span class="broadcast-badge"><?= lucide('megaphone', 'h-4 w-4') ?> Broadcast</span>
        </div>

        <div class="broadcast-notice" role="note">
            <?= lucide('info', 'broadcast-notice-icon') ?>
            <div>
                <strong>การประกาศเคารพการตั้งค่าของผู้ใช้</strong>
                <p>ผู้ใช้ที่ปิด notification "ประกาศจากผู้ดูแลระบบ" ใน <a href="<?= e(url('/profile/notifications')) ?>">การตั้งค่า notification</a> ของตนเองจะไม่ได้รับประกาศนี้</p>
            </div>
        </div>

        <form id="broadcast-form" method="post" action="<?= e(url('/admin/broadcast')) ?>" class="stack-md"
              data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>

            <div class="field-group">
                <label class="field-label" for="broadcast_title">หัวข้อ <span class="required">*</span></label>
                <input id="broadcast_title" class="input" type="text" name="title"
                       required maxlength="200"
                       value="<?= e($oldTitle) ?>"
                       placeholder="เช่น ประกาศปิดระบบเพื่อบำรุงรักษา"
                       data-char-counter="broadcast_title_counter" data-char-max="200">
                <p class="field-hint">
                    <span></span>
                    <span class="char-counter" id="broadcast_title_counter" aria-live="polite"><span class="char-counter-now"><?= mb_strlen($oldTitle) ?></span>/200</span>
                </p>
            </div>

            <div class="field-group">
                <label class="field-label" for="broadcast_message">ข้อความ <span class="required">*</span></label>
                <textarea id="broadcast_message" class="input" name="message"
                          required maxlength="2000" rows="8"
                          placeholder="รายละเอียดประกาศที่ต้องการแจ้งผู้ใช้..."
                          data-char-counter="broadcast_message_counter" data-char-max="2000"><?= e($oldMessage) ?></textarea>
                <p class="field-hint">
                    <span>รองรับ plain text (ขึ้นบรรทัดใหม่ได้)</span>
                    <span class="char-counter" id="broadcast_message_counter" aria-live="polite"><span class="char-counter-now"><?= mb_strlen($oldMessage) ?></span>/2,000</span>
                </p>
            </div>

            <div class="broadcast-section-divider" aria-hidden="true">
                <span>ปลายทาง</span>
            </div>

            <div class="field-group">
                <label class="field-label" for="broadcast_role_filter">ส่งถึง</label>
                <div class="role-filter-row">
                    <select id="broadcast_role_filter" class="input" name="role_filter" data-recipient-target="broadcast_recipient_count">
                        <?php foreach ($roleOptions as $value => $label): ?>
                            <option value="<?= e((string) $value) ?>"
                                    data-count="<?= (int) ($counts[(string) $value] ?? 0) ?>"
                                    <?= $oldRoleFilter === (string) $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="broadcast_recipient_count" class="recipient-count-chip" aria-live="polite">
                        <?= lucide('users', 'h-4 w-4') ?>
                        <span>ส่งสูงสุด <strong data-recipient-number><?= number_format($initialCount) ?></strong> คน</span>
                    </span>
                </div>
                <p class="field-hint">ไม่นับบัญชีที่ปิดใช้งาน · บัญชีของคุณเอง · ผู้ที่ปิดรับประกาศ</p>
            </div>

            <div class="button-row broadcast-actions">
                <button type="button" class="btn btn-primary btn-lg" data-confirm-modal-trigger="broadcast-confirm-modal">
                    <?= lucide('send', 'button-icon') ?>
                    <span>ส่งประกาศ</span>
                </button>
                <?= render_partial('partials/components/button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'ghost',
                    'href' => '/admin',
                ]) ?>
            </div>
        </form>

        <?= render_partial('partials/components/confirm-modal', [
            'id' => 'broadcast-confirm-modal',
            'title' => 'ยืนยันการส่งประกาศ',
            'icon' => 'megaphone',
            'lead' => 'การกระทำนี้จะส่งทันทีและไม่สามารถยกเลิกได้',
            'tone' => 'primary',
            'confirm_label' => 'ยืนยันส่ง',
            'cancel_label' => 'ยกเลิก',
            'summary_slot' => '<dl class="confirm-modal-summary">'
                . '<div><dt>หัวข้อ</dt><dd data-summary-title>—</dd></div>'
                . '<div><dt>ข้อความ</dt><dd data-summary-message class="confirm-modal-message">—</dd></div>'
                . '<div><dt>ส่งถึง</dt><dd><span data-summary-role>—</span> · สูงสุด <strong data-summary-count>0</strong> คน</dd></div>'
                . '</dl>',
        ]) ?>

        <style>
            /* C1: Constrain entire page to 780px so hero + panel align */
            .broadcast-page { max-width: 780px; margin-left: auto; margin-right: auto; }
            .broadcast-page > .page-hero,
            .broadcast-page > .panel-card { max-width: 780px; margin-left: auto; margin-right: auto; width: 100%; box-sizing: border-box; }

            /* C2: Character counter UX */
            .broadcast-page .field-hint { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
            .broadcast-page .char-counter { font-variant-numeric: tabular-nums; font-size: .78rem; color: var(--text-muted, #94a3b8); white-space: nowrap; flex-shrink: 0; }
            .broadcast-page .char-counter.is-near-limit { color: rgb(234, 179, 8); font-weight: 600; }
            .broadcast-page .char-counter.is-at-limit { color: rgb(239, 68, 68); font-weight: 700; }

            /* I2: Prominent opt-out notice */
            .broadcast-notice { display: flex; align-items: flex-start; gap: .65rem; padding: .85rem 1rem; border-radius: 12px; background: rgba(56, 189, 248, .08); border: 1px solid rgba(56, 189, 248, .25); color: var(--text); margin-bottom: .25rem; }
            .broadcast-notice-icon { width: 18px; height: 18px; flex-shrink: 0; color: rgb(56, 189, 248); margin-top: 2px; }
            .broadcast-notice strong { display: block; font-size: .9rem; margin-bottom: .2rem; }
            .broadcast-notice p { margin: 0; font-size: .82rem; line-height: 1.5; color: var(--text-muted, #94a3b8); }
            .broadcast-notice a { color: rgb(129, 140, 248); text-decoration: underline; text-underline-offset: 2px; }

            /* I1: Recipient count chip */
            .role-filter-row { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
            .role-filter-row .input { flex: 1 1 240px; min-width: 0; }
            .recipient-count-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem .75rem; font-size: .85rem; color: var(--text); background: rgba(99, 102, 241, .1); border: 1px solid rgba(99, 102, 241, .25); border-radius: 99px; white-space: nowrap; flex-shrink: 0; font-variant-numeric: tabular-nums; }
            .recipient-count-chip strong { color: rgb(129, 140, 248); font-weight: 700; }
            .recipient-count-chip.is-empty { background: rgba(239, 68, 68, .08); border-color: rgba(239, 68, 68, .25); color: rgb(239, 68, 68); }
            .recipient-count-chip.is-empty strong { color: rgb(239, 68, 68); }

            /* I2: Action divider + push cancel right */
            .broadcast-actions { padding-top: .9rem; border-top: 1px solid var(--glass-border, rgba(255,255,255,.08)); margin-top: .25rem; align-items: center; gap: 1rem; }
            .broadcast-actions .btn-ghost { margin-left: auto; }

            /* N1: Tone-down Broadcast badge — indigo info instead of warning yellow */
            .broadcast-badge { display: inline-flex; align-items: center; gap: .35rem; padding: .3rem .6rem; font-size: .72rem; font-weight: 600; letter-spacing: .02em; color: rgb(165, 180, 252); background: rgba(99, 102, 241, .12); border: 1px solid rgba(99, 102, 241, .25); border-radius: 99px; white-space: nowrap; }
            .broadcast-badge svg { width: 14px; height: 14px; }

            /* N5: Section divider with caption */
            .broadcast-section-divider { display: flex; align-items: center; gap: .65rem; margin-top: .25rem; }
            .broadcast-section-divider::before, .broadcast-section-divider::after { content: ''; flex: 1; height: 1px; background: var(--glass-border, rgba(255,255,255,.08)); }
            .broadcast-section-divider span { font-size: .7rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-muted, #94a3b8); }

            @media (max-width: 600px) {
                .role-filter-row { flex-direction: column; align-items: stretch; }
                .recipient-count-chip { justify-content: center; }
                .broadcast-actions .btn-ghost { margin-left: 0; }
                /* N4: Char counter readable on small screens */
                .broadcast-page .char-counter { font-size: .85rem; }
            }
        </style>

        <script>
            (function () {
                const inputs = document.querySelectorAll('.broadcast-page [data-char-counter]');
                inputs.forEach((input) => {
                    const counterId = input.getAttribute('data-char-counter');
                    const max = parseInt(input.getAttribute('data-char-max') || '0', 10);
                    const counter = document.getElementById(counterId);
                    if (!counter || !max) return;
                    const nowEl = counter.querySelector('.char-counter-now');
                    const update = () => {
                        const len = (input.value || '').length;
                        if (nowEl) nowEl.textContent = len.toLocaleString();
                        const ratio = len / max;
                        counter.classList.toggle('is-near-limit', ratio >= 0.9 && ratio < 1);
                        counter.classList.toggle('is-at-limit', ratio >= 1);
                    };
                    input.addEventListener('input', update);
                    update();
                });

                // I1: Live recipient count
                const roleSelect = document.querySelector('.broadcast-page [data-recipient-target]');
                if (roleSelect) {
                    const chipId = roleSelect.getAttribute('data-recipient-target');
                    const chip = document.getElementById(chipId);
                    const numberEl = chip ? chip.querySelector('[data-recipient-number]') : null;
                    const update = () => {
                        const opt = roleSelect.options[roleSelect.selectedIndex];
                        const count = parseInt(opt?.getAttribute('data-count') || '0', 10);
                        if (numberEl) numberEl.textContent = count.toLocaleString();
                        if (chip) chip.classList.toggle('is-empty', count === 0);
                    };
                    roleSelect.addEventListener('change', update);
                    update();
                }

                // Populate confirm modal summary before opening (global handler does the open)
                const trigger = document.querySelector('[data-confirm-modal-trigger="broadcast-confirm-modal"]');
                const modal = document.getElementById('broadcast-confirm-modal');
                if (trigger && modal) {
                    const titleInput = document.getElementById('broadcast_title');
                    const messageInput = document.getElementById('broadcast_message');
                    const summaryTitle = modal.querySelector('[data-summary-title]');
                    const summaryMessage = modal.querySelector('[data-summary-message]');
                    const summaryRole = modal.querySelector('[data-summary-role]');
                    const summaryCount = modal.querySelector('[data-summary-count]');
                    trigger.__beforeConfirmOpen = () => {
                        const titleVal = (titleInput?.value || '').trim();
                        const messageVal = (messageInput?.value || '').trim();
                        const opt = roleSelect?.options[roleSelect.selectedIndex];
                        if (summaryTitle) summaryTitle.textContent = titleVal || '(ไม่ระบุ)';
                        if (summaryMessage) summaryMessage.textContent = messageVal || '(ไม่ระบุ)';
                        if (summaryRole) summaryRole.textContent = opt?.textContent?.trim() || '—';
                        if (summaryCount) summaryCount.textContent = parseInt(opt?.getAttribute('data-count') || '0', 10).toLocaleString();
                    };
                }
            })();
        </script>
    </section>
</section>
