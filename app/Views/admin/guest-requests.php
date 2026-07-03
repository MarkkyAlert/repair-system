<?php
$statusOptions = [
    'new' => 'รอตรวจสอบ',
    'converted' => 'แปลงเป็น Ticket แล้ว',
    'rejected' => 'ปฏิเสธ',
    '' => 'ทุกสถานะ',
];
$statusTone = [
    'new' => 'warning',
    'converted' => 'success',
    'rejected' => 'danger',
];
$activeStatus = (string) ($selectedStatus ?? 'new');
$tabUrl = static fn (string $status): string => url('/admin/guest-requests' . ($status !== '' ? '?status=' . rawurlencode($status) : ''));
?>
<section class="stack-lg"
    data-live-poll
    data-live-poll-url="<?= e(url('/admin/guest-requests/state')) ?>"
    data-live-poll-key="max_id"
    data-live-poll-baseline="<?= e((string) ($queueMaxId ?? 0)) ?>">
    <h1 class="sr-only">คำขอแจ้งซ่อมจาก QR (Guest)</h1>
    <div class="ticket-live-banner" data-live-poll-banner hidden role="status" aria-live="polite">
        <?= lucide('refresh-cw', 'button-icon') ?>
        <span>มีคำขอแจ้งซ่อมใหม่เข้ามา</span>
        <button type="button" class="btn btn-sm btn-primary" data-live-poll-reload>โหลดใหม่</button>
    </div>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => 'คำขอแจ้งซ่อมจาก Guest QR',
        'description' => 'ผู้ที่สแกน QR แล้วแจ้งปัญหาโดยไม่ต้องล็อกอิน ระบบเก็บไว้รอตรวจสอบก่อนแปลงเป็น Ticket',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าตั้งค่า',
            'variant' => 'secondary',
            'href' => '/admin',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <div class="stat-grid stat-grid-3">
        <?= render_partial('partials/components/card', ['title' => 'รอตรวจ', 'value' => (string) ($totals['new'] ?? 0), 'meta' => 'ต้องดำเนินการ', 'tone' => 'warning', 'icon' => 'clock']) ?>
        <?= render_partial('partials/components/card', ['title' => 'แปลงแล้ว', 'value' => (string) ($totals['converted'] ?? 0), 'meta' => 'กลายเป็น Ticket', 'tone' => 'success', 'icon' => 'check-circle']) ?>
        <?= render_partial('partials/components/card', ['title' => 'ปฏิเสธ', 'value' => (string) ($totals['rejected'] ?? 0), 'meta' => 'ไม่ผ่านเกณฑ์', 'tone' => 'danger', 'icon' => 'triangle-alert']) ?>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title"><?= e($statusOptions[$activeStatus] ?? 'ทุกสถานะ') ?></h2>
                <p class="field-hint">รวม <?= e((string) ($pagination['total'] ?? 0)) ?> รายการ</p>
            </div>
        </div>

        <nav class="preset-bar" aria-label="กรองตามสถานะ">
            <span class="helper-text">สถานะ</span>
            <?php foreach ($statusOptions as $value => $label): ?>
                <a href="<?= e($tabUrl((string) $value)) ?>" class="preset-chip<?= $activeStatus === (string) $value ? ' is-active' : '' ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (($requests ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'qr-code',
                'title' => 'ไม่มีคำขอในสถานะนี้',
                'description' => 'รายการจะปรากฏที่นี่เมื่อมีคนสแกน QR และแจ้งปัญหาเข้ามา',
            ]) ?>
        <?php else: ?>
            <div class="stack-md">
                <?php foreach ($requests as $request): ?>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="metric-icon metric-icon-sm"><?= lucide('qr-code', 'h-4 w-4') ?></span>
                            <div class="collapsible-summary-main">
                                <span class="collapsible-title"><?= e((string) $request['title']) ?></span>
                                <span class="collapsible-subtitle">
                                    <code class="mono"><?= e((string) $request['request_no']) ?></code>
                                    · <?= e((string) $request['guest_name']) ?>
                                    <?php if (!empty($request['asset_code'])): ?>
                                        · <code class="mono"><?= e((string) $request['asset_code']) ?></code>
                                    <?php endif; ?>
                                    · <?= e(human_date((string) $request['created_at'])) ?>
                                </span>
                            </div>
                            <div class="collapsible-meta">
                                <?= render_partial('partials/components/badge', [
                                    'label' => $statusOptions[(string) $request['status']] ?? (string) $request['status'],
                                    'tone' => $statusTone[(string) $request['status']] ?? 'default',
                                ]) ?>
                                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                            </div>
                        </summary>
                        <div class="collapsible-body stack-md">
                            <dl class="description-list">
                                <dt>รายละเอียด</dt><dd><?= nl2br(e((string) ($request['description'] ?? '-'))) ?></dd>
                                <dt>ผู้แจ้ง</dt><dd><?= e((string) $request['guest_name']) ?></dd>
                                <?php if (!empty($request['guest_email'])): ?>
                                    <dt>อีเมล</dt><dd><?= e((string) $request['guest_email']) ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($request['guest_phone'])): ?>
                                    <dt>โทร</dt><dd><?= e((string) $request['guest_phone']) ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($request['asset_name'])): ?>
                                    <dt>ทรัพย์สิน</dt><dd><?= e((string) $request['asset_name']) ?> (<?= e((string) $request['asset_code']) ?>)</dd>
                                <?php endif; ?>
                                <?php if (!empty($request['location_name'])): ?>
                                    <dt>สถานที่</dt><dd><?= e((string) $request['location_name']) ?></dd>
                                <?php endif; ?>
                                <dt>เวลา</dt><dd><?= e(human_date((string) $request['created_at'])) ?></dd>
                            </dl>

                            <?php if ((string) $request['status'] === 'new'): ?>
                                <form method="post" action="<?= e(url('/admin/guest-requests/' . (int) $request['id'] . '/convert')) ?>" class="stack-md" onsubmit="return confirm('ยืนยันแปลงเป็น ticket?');">
                                    <?= csrf_field() ?>
                                    <div class="content-grid">
                                        <div class="field-group">
                                            <label class="field-label">ความสำคัญ <span class="required">*</span></label>
                                            <select name="priority_id" class="input" required>
                                                <option value="">เลือกความสำคัญ</option>
                                                <?php foreach (($priorities ?? []) as $p): ?>
                                                    <option value="<?= e((string) $p['id']) ?>"><?= e((string) $p['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label">หมวดหมู่ <span class="required">*</span></label>
                                            <select name="ticket_category_id" class="input" required>
                                                <option value="">เลือกหมวดหมู่</option>
                                                <?php foreach (($categories ?? []) as $c): ?>
                                                    <option value="<?= e((string) $c['id']) ?>"><?= e((string) $c['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="button-row">
                                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'แปลงเป็น Ticket', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                                    </div>
                                </form>

                                <div class="delete-zone">
                                    <form method="post" action="<?= e(url('/admin/guest-requests/' . (int) $request['id'] . '/reject')) ?>" class="stack-md" onsubmit="return confirm('ยืนยันปฏิเสธคำขอนี้?');">
                                        <?= csrf_field() ?>
                                        <div class="field-group">
                                            <label class="field-label">เหตุผลการปฏิเสธ</label>
                                            <input name="note" type="text" class="input" placeholder="เช่น spam, ทดสอบ, ไม่ใช่ปัญหาจริง">
                                        </div>
                                        <div class="button-row">
                                            <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ปฏิเสธ', 'variant' => 'danger', 'icon' => 'x', 'size' => 'sm']) ?>
                                        </div>
                                    </form>
                                </div>
                            <?php elseif ((string) $request['status'] === 'converted' && !empty($request['converted_ticket_id'])): ?>
                                <?= render_partial('partials/components/button', [
                                    'label' => 'ดู Ticket #' . (int) $request['converted_ticket_id'],
                                    'variant' => 'secondary',
                                    'href' => '/tickets/' . (int) $request['converted_ticket_id'],
                                    'icon' => 'arrow-right',
                                    'iconPosition' => 'right',
                                ]) ?>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
