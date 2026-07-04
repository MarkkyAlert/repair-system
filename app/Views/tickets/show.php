<?php
// Determine the primary CTA for current viewer based on workflow flags.
$primaryCta = null;
$primaryAnchor = null;
if (!empty($workflow['canReview'])) {
    $primaryCta = ['label' => 'อนุมัติ / ปฏิเสธ', 'icon' => 'check-circle'];
    $primaryAnchor = '#action-approval';
} elseif (!empty($workflow['canAssign'])) {
    $primaryCta = ['label' => 'มอบหมายช่าง', 'icon' => 'users'];
    $primaryAnchor = '#action-assign';
} elseif (!empty($workflow['canAccept'])) {
    $primaryCta = ['label' => 'รับงาน', 'icon' => 'check-circle'];
    $primaryAnchor = '#action-accept';
} elseif (!empty($workflow['canStart'])) {
    $primaryCta = ['label' => 'เริ่มดำเนินงาน', 'icon' => 'zap'];
    $primaryAnchor = '#action-start';
} elseif (!empty($workflow['canResolve'])) {
    $primaryCta = ['label' => 'สรุปผลการซ่อม', 'icon' => 'check-circle'];
    $primaryAnchor = '#action-resolve';
} elseif (!empty($workflow['canComplete'])) {
    $primaryCta = ['label' => 'ยืนยันปิดงาน + ให้คะแนน', 'icon' => 'star'];
    $primaryAnchor = '#action-complete';
} elseif (!empty($workflow['canReopen'])) {
    $primaryCta = ['label' => 'ขอแก้งานซ้ำ', 'icon' => 'rotate-ccw'];
    $primaryAnchor = '#action-reopen';
}
?>
<section class="stack-lg"
    data-ticket-live
    data-ticket-state-url="<?= e(url('/tickets/' . $ticket['id'] . '/state')) ?>"
    data-ticket-status="<?= e((string) $ticket['status']) ?>"
    data-ticket-comment-count="<?= e((string) count($comments)) ?>">
    <h1 class="sr-only"><?= e($ticket['title']) ?> — <?= e($ticket['ticket_no']) ?></h1>
    <div class="ticket-live-banner" data-ticket-live-banner hidden role="status" aria-live="polite">
        <?= lucide('refresh-cw', 'button-icon') ?>
        <span>รายการนี้มีอัปเดตใหม่</span>
        <button type="button" class="btn btn-sm btn-primary" data-ticket-live-reload>โหลดใหม่</button>
    </div>
    <?= render_partial('partials/components/breadcrumb', [
        'items' => [
            ['label' => 'Tickets', 'href' => '/tickets'],
            ['label' => (string) $ticket['ticket_no']],
        ],
    ]) ?>
    <!-- Sticky Action Bar -->
    <div class="action-bar no-print">
        <div class="action-bar-left">
            <a href="<?= e(url('/tickets')) ?>" class="icon-button" aria-label="กลับหน้ารายการ"><?= lucide('chevrons-left', 'h-4 w-4') ?></a>
            <div>
                <code class="mono"><?= e($ticket['ticket_no']) ?></code>
                <strong class="action-bar-title"><?= e($ticket['title']) ?></strong>
                <span class="helper-text">แจ้งเมื่อ <?= e(human_date($ticket['requested_at'])) ?></span>
                <div class="action-bar-badges">
                    <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                    <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                </div>
            </div>
        </div>
        <div class="action-bar-right">
            <?php if (!empty($workflow['canDuplicate'])): ?>
                <?= render_partial('partials/components/button', ['label' => 'เปิด Ticket ใหม่จากรายการนี้', 'variant' => 'secondary', 'href' => '/tickets/' . $ticket['id'] . '/duplicate', 'icon' => 'copy']) ?>
            <?php endif; ?>
            <?php if ($primaryCta): ?>
                <a href="<?= e($primaryAnchor) ?>" class="btn btn-primary btn-md">
                    <?= lucide($primaryCta['icon'], 'button-icon') ?>
                    <span><?= e($primaryCta['label']) ?></span>
                </a>
            <?php endif; ?>
            <details class="ticket-print-menu">
                <summary class="btn btn-secondary btn-md" aria-label="ตัวเลือกการพิมพ์">
                    <?= lucide('printer', 'button-icon') ?>
                    <span>พิมพ์</span>
                    <?= lucide('chevron-down', 'h-3 w-3') ?>
                </summary>
                <div class="ticket-print-menu-list">
                    <a href="<?= e($ticket['print_url']) ?>"><?= lucide('file-text', 'h-4 w-4') ?><span>พิมพ์ A4</span></a>
                    <a href="<?= e($ticket['print_a5_url']) ?>"><?= lucide('file-text', 'h-4 w-4') ?><span>พิมพ์ A5</span></a>
                    <a href="<?= e($ticket['print_pdf_url']) ?>"><?= lucide('download', 'h-4 w-4') ?><span>ดาวน์โหลด PDF</span></a>
                </div>
            </details>
        </div>
    </div>

    <!-- Ticket Status Hero -->
    <section class="panel-card ticket-status-hero">
        <div class="panel-head">
            <h2 class="panel-title">สถานะและความคืบหน้า</h2>
            <div class="button-row">
                <?= render_partial('partials/components/badge', ['label' => $ticket['approval_label'], 'tone' => $ticket['approval_tone']]) ?>
                <?= render_partial('partials/components/badge', ['label' => $ticket['sla_overview_label'], 'tone' => $ticket['sla_overview_tone']]) ?>
            </div>
        </div>
        <p class="body-text"><?= e($ticket['description']) ?></p>
        <?php if (!empty($attachments)): ?>
            <div class="attachment-grid" aria-label="ไฟล์แนบ Ticket">
                <?php foreach ($attachments as $attachment): ?>
                    <?php $isImage = str_starts_with((string) ($attachment['mime_type'] ?? ''), 'image/'); ?>
                    <a class="attachment-card<?= $isImage ? '' : ' attachment-card-doc' ?>" href="<?= e(url($attachment['url'])) ?>" target="_blank" rel="noopener">
                        <?php if ($isImage): ?>
                            <img src="<?= e(url($attachment['url'])) ?>" alt="<?= e($attachment['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="attachment-doc-icon"><?= lucide('file-text', 'h-8 w-8') ?></span>
                        <?php endif; ?>
                        <span><?= e($attachment['name']) ?> · <?= e($attachment['size_label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
            $currentStatus = (string) ($ticket['status'] ?? '');
            $progressSteps = ['pending_approval' => 'รออนุมัติ', 'approved' => 'อนุมัติ', 'assigned' => 'มอบหมาย', 'in_progress' => 'ดำเนินการ', 'resolved' => 'รอตรวจรับ', 'completed' => 'เสร็จสิ้น'];
            $stepKeys = array_keys($progressSteps);

            // Furthest stage actually reached, inferred from milestone timestamps —
            // reliable even when the ticket has left the happy path.
            $reachedIndex = 0;
            foreach (['approved' => 'approved_at', 'assigned' => 'assigned_at', 'in_progress' => 'started_at', 'resolved' => 'resolved_at', 'completed' => 'completed_at'] as $stage => $field) {
                if ((string) ($ticket[$field] ?? '-') !== '-') {
                    $reachedIndex = array_search($stage, $stepKeys, true);
                }
            }

            // Terminal (negative) states leave the happy path; on_hold pauses it; closed completes it.
            $terminalLabels = ['rejected' => 'ถูกปฏิเสธ', 'cancelled' => 'ยกเลิกแล้ว'];
            $isTerminal = array_key_exists($currentStatus, $terminalLabels);
            $isOnHold = $currentStatus === 'on_hold';
            if ($currentStatus === 'closed') {
                $reachedIndex = array_search('completed', $stepKeys, true);
            }

            $activeStatus = $isOnHold ? 'in_progress' : $currentStatus;
            $currentIndex = array_search($activeStatus, $stepKeys, true);
            if ($currentIndex === false) {
                $currentIndex = $reachedIndex;
            }
        ?>
        <?php if ($isOnHold): ?>
            <div class="button-row">
                <?= render_partial('partials/components/badge', ['label' => 'พักงานชั่วคราว', 'tone' => 'warning']) ?>
                <span class="helper-text">งานถูกพักไว้ชั่วคราว จะกลับมาดำเนินการต่อในขั้น “ดำเนินการ”</span>
            </div>
        <?php endif; ?>
        <ol class="workflow-progress" aria-label="สถานะการดำเนินงาน">
            <?php foreach ($progressSteps as $statusKey => $statusLabel): ?>
                <?php
                    $stepIndex = array_search($statusKey, $stepKeys, true);
                    if ($isTerminal) {
                        $isComplete = $stepIndex < $reachedIndex;
                        $isCurrent = false;
                        $isTerminalStep = $stepIndex === $reachedIndex;
                        $isSkipped = $stepIndex > $reachedIndex;
                    } else {
                        $isComplete = $currentIndex !== false && $stepIndex <= $currentIndex;
                        $isCurrent = $statusKey === $activeStatus || ($currentStatus === 'accepted' && $statusKey === 'assigned');
                        $isTerminalStep = false;
                        $isSkipped = false;
                    }
                    $stepClasses = 'workflow-progress-step'
                        . ($isComplete ? ' is-complete' : '')
                        . ($isCurrent ? ' is-current' : '')
                        . ($isTerminalStep ? ' is-terminal' : '')
                        . ($isSkipped ? ' is-skipped' : '');
                ?>
                <li class="<?= $stepClasses ?>">
                    <i></i><?= e($isTerminalStep ? $terminalLabels[$currentStatus] : $statusLabel) ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <!-- Overview + Assignment grid -->
    <div class="content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ข้อมูลแจ้งซ่อม</h2>
                <span class="metric-icon metric-icon-sm"><?= lucide('info', 'h-4 w-4') ?></span>
            </div>
            <dl class="detail-list">
                <dt>ผู้แจ้ง</dt>
                <dd><?= e($ticket['requester_name'] ?: '-') ?></dd>
                <dt>อีเมล</dt>
                <dd><?= e($ticket['requester_email'] ?: '-') ?></dd>
                <dt>เบอร์โทร</dt>
                <dd><?= e($ticket['requester_phone'] ?: '-') ?></dd>
                <dt>หมวดหมู่</dt>
                <dd><?= e($ticket['category_name'] ?: '-') ?></dd>
                <dt>ช่องทาง</dt>
                <dd><?= e($ticket['channel_label'] ?: '-') ?></dd>
                <dt>ผลกระทบ / ความเร่งด่วน</dt>
                <dd><?= e($ticket['impact_level']) ?> / <?= e($ticket['urgency_level']) ?></dd>
                <dt>สถานที่</dt>
                <dd><?= e($ticket['location_detail'] ?: '-') ?></dd>
                <dt>อุปกรณ์</dt>
                <dd><?= $ticket['asset_code'] ? '<code class="mono">' . e($ticket['asset_code']) . '</code> ' . e($ticket['asset_name']) : '<span class="is-empty">ไม่ระบุ</span>' ?></dd>
            </dl>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">การมอบหมายและ SLA</h2>
                <div class="button-row">
                    <?= render_partial('partials/components/badge', ['label' => 'ตอบรับ · ' . $ticket['sla_response']['label'], 'tone' => $ticket['sla_response']['tone']]) ?>
                    <?= render_partial('partials/components/badge', ['label' => 'แก้ไข · ' . $ticket['sla_resolution']['label'], 'tone' => $ticket['sla_resolution']['tone']]) ?>
                </div>
            </div>
            <dl class="detail-list">
                <dt>หัวหน้างาน</dt>
                <dd><?= e($ticket['manager_name'] ?: '-') ?></dd>
                <dt>ช่างเทคนิค</dt>
                <dd><?= e($ticket['technician_name'] ?: '-') ?></dd>
                <dt>แจ้งเมื่อ</dt>
                <dd><?= e(human_date($ticket['requested_at'])) ?></dd>
                <?php if (!empty($ticket['approved_at'])): ?>
                    <dt>อนุมัติเมื่อ</dt>
                    <dd><?= e(human_date($ticket['approved_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['assigned_at'])): ?>
                    <dt>มอบหมายเมื่อ</dt>
                    <dd><?= e(human_date($ticket['assigned_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['first_response_at'])): ?>
                    <dt>ตอบรับครั้งแรก</dt>
                    <dd><?= e(human_date($ticket['first_response_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['started_at'])): ?>
                    <dt>เริ่มงานเมื่อ</dt>
                    <dd><?= e(human_date($ticket['started_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['response_due_at'])): ?>
                    <dt>เป้าตอบรับ</dt>
                    <dd>
                        <?= e(human_date($ticket['response_due_at'])) ?>
                        <?php if (($ticket['sla_response']['status'] ?? '') === 'pending' && (int) ($ticket['sla_response']['target_ts'] ?? 0) > 0): ?>
                            <span class="sla-countdown" data-sla-countdown data-sla-target-ts="<?= (int) $ticket['sla_response']['target_ts'] ?>"><span data-sla-countdown-label>กำลังคำนวณ…</span></span>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
                <?php if (!empty($ticket['resolution_due_at'])): ?>
                    <dt>เป้าแก้ไข</dt>
                    <dd>
                        <?= e(human_date($ticket['resolution_due_at'])) ?>
                        <?php if (($ticket['sla_resolution']['status'] ?? '') === 'pending' && (int) ($ticket['sla_resolution']['target_ts'] ?? 0) > 0): ?>
                            <span class="sla-countdown" data-sla-countdown data-sla-target-ts="<?= (int) $ticket['sla_resolution']['target_ts'] ?>"><span data-sla-countdown-label>กำลังคำนวณ…</span></span>
                        <?php endif; ?>
                    </dd>
                <?php endif; ?>
                <?php if (!empty($ticket['resolved_at'])): ?>
                    <dt>แก้เสร็จเมื่อ</dt>
                    <dd><?= e(human_date($ticket['resolved_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['completed_at'])): ?>
                    <dt>ปิดงานเมื่อ</dt>
                    <dd><?= e(human_date($ticket['completed_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($ticket['cancelled_at'])): ?>
                    <dt>ยกเลิกเมื่อ</dt>
                    <dd><?= e(human_date($ticket['cancelled_at'])) ?></dd>
                <?php endif; ?>
            </dl>
        </section>
    </div>

    <!-- Manager actions -->
    <?php if (!empty($workflow['managerCanAct'])): ?>
        <section class="panel-card stack-md" id="action-approval">
            <div class="panel-head">
                <h2 class="panel-title">อนุมัติและมอบหมายงาน</h2>
                <span class="badge badge-info">หัวหน้างาน</span>
            </div>

            <?php if (!empty($workflow['canReview'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/approve')) ?>" class="action-form action-form-success">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-success"><?= lucide('check-circle', 'h-5 w-5') ?></span>
                        <div>
                            <h3>อนุมัติรายการ</h3>
                            <p>ส่งต่อให้ทีมจัดสรรช่าง</p>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="approval-note" class="field-label">หมายเหตุ (ไม่บังคับ)</label>
                        <textarea id="approval-note" name="note" class="input" rows="3" placeholder="เช่น อนุมัติให้ช่างเข้าดำเนินการภายใน SLA"><?= e((string) ($workflow['defaults']['note'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'อนุมัติ', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                </form>

                <details class="reject-disclosure">
                    <summary class="btn btn-ghost btn-sm">
                        <?= lucide('triangle-alert', 'button-icon') ?>
                        <span>ปฏิเสธรายการนี้แทน</span>
                    </summary>
                    <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/reject')) ?>" class="action-form action-form-danger">
                        <?= csrf_field() ?>
                        <div class="action-form-head">
                            <span class="action-form-icon tone-danger"><?= lucide('triangle-alert', 'h-5 w-5') ?></span>
                            <div>
                                <h3>ปฏิเสธรายการ</h3>
                                <p>ระบุเหตุผลให้ผู้แจ้งทราบ</p>
                            </div>
                        </div>
                        <div class="field-group">
                            <label for="reject-note" class="field-label">เหตุผลในการปฏิเสธ <span class="required">*</span></label>
                            <textarea id="reject-note" name="note" class="input" rows="3" required placeholder="ระบุเหตุผลที่ไม่สามารถอนุมัติรายการนี้ได้"><?= e((string) ($workflow['defaults']['note'] ?? '')) ?></textarea>
                        </div>
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ปฏิเสธ', 'variant' => 'danger', 'icon' => 'x']) ?>
                    </form>
                </details>
            <?php endif; ?>

            <?php if (!empty($workflow['canAssign'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/assign')) ?>" class="action-form action-form-info" id="action-assign">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-info"><?= lucide('users', 'h-5 w-5') ?></span>
                        <div>
                            <h3>มอบหมายช่างเทคนิค</h3>
                            <p>เลือกช่างและให้คำสั่งการดำเนินงาน</p>
                        </div>
                    </div>

                    <div class="content-grid">
                        <div class="field-group">
                            <label for="technician_id" class="field-label">ช่างเทคนิค <span class="required">*</span></label>
                            <select id="technician_id" name="technician_id" class="input" required>
                                <option value="">เลือกช่างเทคนิค</option>
                                <?php foreach (($workflow['technicians'] ?? []) as $technician): ?>
                                    <option value="<?= e((string) $technician['id']) ?>"<?= (string) ($workflow['defaults']['technician_id'] ?? '') === (string) $technician['id'] ? ' selected' : '' ?>><?= e($technician['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field-group">
                            <label for="instructions" class="field-label">คำสั่งการทำงาน</label>
                            <textarea id="instructions" name="instructions" class="input" rows="3" placeholder="เช่น ตรวจสอบ uplink, สายสัญญาณ และทดสอบหลังแก้ไข"><?= e((string) ($workflow['defaults']['instructions'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'มอบหมายงานให้ช่าง', 'variant' => 'primary', 'icon' => 'send', 'iconPosition' => 'right']) ?>
                </form>
            <?php endif; ?>

            <?php if (empty($workflow['canReview']) && empty($workflow['canAssign'])): ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'clipboard-list',
                    'title' => 'ยังไม่มีงานที่ต้องทำในตอนนี้',
                    'description' => 'รายการนี้อาจถูกอนุมัติ ปฏิเสธ หรือมอบหมายไปแล้ว และรอขั้นตอนถัดไปของ workflow',
                ]) ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Technician actions -->
    <?php if (!empty($workflow['technicianCanAct'])): ?>
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ใบสั่งงานของช่างเทคนิค</h2>
                <span class="badge badge-info">ช่างเทคนิค</span>
            </div>

            <dl class="detail-list">
                <dt>เลขใบสั่งงาน</dt>
                <dd><code class="mono"><?= e((string) ($workflow['workOrder']['number'] ?? '-')) ?></code></dd>
                <dt>สถานะ</dt>
                <dd><?= e((string) ($workflow['workOrder']['status'] ?? '-')) ?></dd>
                <dt>คำสั่งงาน</dt>
                <dd><?= e((string) (($workflow['workOrder']['instructions'] ?? '') !== '' ? $workflow['workOrder']['instructions'] : '-')) ?></dd>
                <?php if (!empty($workflow['workOrder']['assigned_at'])): ?>
                    <dt>มอบหมายเมื่อ</dt>
                    <dd><?= e(human_date($workflow['workOrder']['assigned_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($workflow['workOrder']['accepted_at'])): ?>
                    <dt>รับงานเมื่อ</dt>
                    <dd><?= e(human_date($workflow['workOrder']['accepted_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($workflow['workOrder']['started_at'])): ?>
                    <dt>เริ่มงานเมื่อ</dt>
                    <dd><?= e(human_date($workflow['workOrder']['started_at'])) ?></dd>
                <?php endif; ?>
                <?php if (!empty($workflow['workOrder']['completed_at'])): ?>
                    <dt>เสร็จสิ้นเมื่อ</dt>
                    <dd><?= e(human_date($workflow['workOrder']['completed_at'])) ?></dd>
                <?php endif; ?>
            </dl>

            <?php if (!empty($workflow['canAccept'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/accept')) ?>" class="action-form action-form-info" id="action-accept">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-info"><?= lucide('check-circle', 'h-5 w-5') ?></span>
                        <div><h3>รับงาน</h3><p>ยืนยันว่าคุณรับงานนี้แล้ว</p></div>
                    </div>
                    <div class="field-group">
                        <label for="accept_note" class="field-label">หมายเหตุ</label>
                        <textarea id="accept_note" name="accept_note" class="input" rows="2" placeholder="เช่น รับงานแล้ว กำลังเตรียมเข้าหน้างาน"><?= e((string) ($workflow['defaults']['accept_note'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ยืนยันรับงาน', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($workflow['canStart'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/start')) ?>" class="action-form action-form-warning" id="action-start">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-warning"><?= lucide('zap', 'h-5 w-5') ?></span>
                        <div><h3>เริ่มดำเนินงาน</h3><p>ระบบจะเริ่มจับเวลา SLA แก้ไข</p></div>
                    </div>
                    <div class="field-group">
                        <label for="start_note" class="field-label">หมายเหตุ</label>
                        <textarea id="start_note" name="start_note" class="input" rows="2" placeholder="เช่น เริ่มตรวจสอบอุปกรณ์และระบบที่เกี่ยวข้อง"><?= e((string) ($workflow['defaults']['start_note'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'เริ่มงาน', 'variant' => 'primary', 'icon' => 'zap']) ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($workflow['canResolve'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/resolve')) ?>" class="action-form action-form-success" id="action-resolve">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-success"><?= lucide('check-circle', 'h-5 w-5') ?></span>
                        <div><h3>สรุปผลการซ่อม</h3><p>บันทึกสิ่งที่ค้นพบและวิธีแก้ไขเพื่อปิดงาน</p></div>
                    </div>
                    <div class="field-group">
                        <label for="diagnosis_summary" class="field-label">ผลการวิเคราะห์สาเหตุ <span class="required">*</span></label>
                        <textarea id="diagnosis_summary" name="diagnosis_summary" class="input" rows="3" required placeholder="อธิบายสาเหตุที่ตรวจพบ"><?= e((string) ($workflow['defaults']['diagnosis_summary'] ?? '')) ?></textarea>
                    </div>
                    <div class="field-group">
                        <label for="resolution_summary" class="field-label">วิธีแก้ไขและผลลัพธ์ <span class="required">*</span></label>
                        <textarea id="resolution_summary" name="resolution_summary" class="input" rows="3" required placeholder="อธิบายวิธีแก้ไขและผลลัพธ์หลังทดสอบ"><?= e((string) ($workflow['defaults']['resolution_summary'] ?? '')) ?></textarea>
                    </div>
                    <div class="field-group">
                        <label for="labor_minutes" class="field-label">เวลาที่ใช้ (นาที)</label>
                        <input id="labor_minutes" name="labor_minutes" type="number" min="0" class="input" value="<?= e((string) ($workflow['defaults']['labor_minutes'] ?? '0')) ?>">
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'สรุปงาน', 'variant' => 'primary', 'icon' => 'check-circle']) ?>
                </form>
            <?php endif; ?>

            <?php if (empty($workflow['canAccept']) && empty($workflow['canStart']) && empty($workflow['canResolve'])): ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'clipboard-list',
                    'title' => 'ไม่มีขั้นตอนของช่างในตอนนี้',
                    'description' => 'งานนี้อาจถูกดำเนินการไปแล้ว หรือรอสถานะจากผู้เกี่ยวข้องขั้นถัดไป',
                ]) ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Requester confirmation -->
    <?php if (!empty($workflow['requesterCanAct'])): ?>
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">การดำเนินการของผู้แจ้ง</h2>
                <span class="badge badge-info">ผู้แจ้ง</span>
            </div>

            <?php if (!empty($workflow['canCancel'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/cancel')) ?>" class="action-form action-form-danger" id="action-cancel" data-confirm-submit="ยืนยันการยกเลิก Ticket นี้? การดำเนินการนี้ย้อนกลับไม่ได้">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-danger"><?= lucide('x', 'h-5 w-5') ?></span>
                        <div><h3>ยกเลิก Ticket</h3><p>ยกเลิกได้เฉพาะก่อนมีการมอบหมายงานให้ช่าง</p></div>
                    </div>
                    <div class="field-group">
                        <label for="cancel_note" class="field-label">เหตุผลในการยกเลิก <span class="required">*</span></label>
                        <textarea id="cancel_note" name="cancel_note" class="input" rows="3" required placeholder="ระบุเหตุผลที่ไม่ต้องการดำเนินการ Ticket นี้ต่อ"><?= e((string) ($workflow['defaults']['cancel_note'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ยืนยันยกเลิก Ticket', 'variant' => 'danger', 'icon' => 'x']) ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($workflow['canComplete'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/complete')) ?>" class="action-form action-form-success" id="action-complete">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-success"><?= lucide('star', 'h-5 w-5') ?></span>
                        <div><h3>ยืนยันปิดงาน</h3><p>เมื่อยืนยันแล้ว ระบบจะปิดงานและบันทึกคะแนน</p></div>
                    </div>
                    <div class="field-group">
                        <label for="closure_note" class="field-label">หมายเหตุการปิดงาน</label>
                        <textarea id="closure_note" name="closure_note" class="input" rows="3" placeholder="เช่น ทดลองใช้งานแล้วใช้งานได้ตามปกติ"><?= e((string) ($workflow['defaults']['closure_note'] ?? '')) ?></textarea>
                    </div>
                    <div class="field-group">
                        <label class="field-label">คะแนนความพึงพอใจ <span class="required">*</span></label>
                        <fieldset class="star-rating">
                            <legend class="sr-only">คะแนนความพึงพอใจ</legend>
                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                <input type="radio" name="score" id="star<?= $i ?>" value="<?= $i ?>"<?= (string) ($workflow['defaults']['score'] ?? '') === (string) $i ? ' checked' : '' ?> required>
                                <label for="star<?= $i ?>" title="<?= $i ?> ดาว"><?= $i ?></label>
                            <?php endfor; ?>
                        </fieldset>
                    </div>
                    <div class="field-group">
                        <label for="feedback" class="field-label">ความคิดเห็นเพิ่มเติม</label>
                        <textarea id="feedback" name="feedback" class="input" rows="3" placeholder="เช่น ช่างอธิบายสาเหตุชัดเจนและแก้ปัญหาได้รวดเร็ว"><?= e((string) ($workflow['defaults']['feedback'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ยืนยันปิดงานและส่งคะแนน', 'variant' => 'primary', 'icon' => 'star', 'iconPosition' => 'right']) ?>
                </form>
            <?php endif; ?>

            <?php if (!empty($workflow['canReopen'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/reopen')) ?>" class="action-form action-form-warning" id="action-reopen">
                    <?= csrf_field() ?>
                    <div class="action-form-head">
                        <span class="action-form-icon tone-warning"><?= lucide('rotate-ccw', 'h-5 w-5') ?></span>
                        <div><h3>ขอแก้งานซ้ำ</h3><p>ส่งงานกลับไปให้ช่างดำเนินการต่อ โดยใช้ช่างคนเดิมและรีเซ็ต SLA ของรอบนี้</p></div>
                    </div>
                    <div class="field-group">
                        <label for="reopen_note" class="field-label">เหตุผลที่ต้องการให้แก้ไขซ้ำ <span class="required">*</span></label>
                        <textarea id="reopen_note" name="reopen_note" class="input" rows="3" required placeholder="เช่น ยังใช้งานไม่ได้ตามปกติ หรืออาการเดิมกลับมาอีกครั้ง"><?= e((string) ($workflow['defaults']['reopen_note'] ?? '')) ?></textarea>
                    </div>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ส่งกลับไปแก้งานซ้ำ', 'variant' => 'warning', 'icon' => 'rotate-ccw']) ?>
                </form>
            <?php endif; ?>

            <?php if (empty($workflow['canComplete']) && empty($workflow['canReopen']) && empty($workflow['canCancel'])): ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'clipboard-list',
                    'title' => 'ยังไม่ถึงขั้นตอนของผู้แจ้ง',
                    'description' => 'ระบบจะแสดงฟอร์มยืนยันผลการซ่อมหรือขอแก้งานซ้ำเมื่อช่างสรุปงานเป็น Resolved แล้ว',
                ]) ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Resolution summary (history) -->
    <?php if ($ticket['resolution_summary'] !== '' || $ticket['closure_note'] !== '' || $ticket['rating_score'] > 0): ?>
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">ผลการดำเนินงาน</h2>
            </div>
            <dl class="detail-list">
                <?php if ($ticket['resolution_summary'] !== ''): ?>
                    <dt>สรุปการแก้ไข</dt>
                    <dd><?= e($ticket['resolution_summary']) ?></dd>
                <?php endif; ?>
                <?php if ($ticket['closure_note'] !== ''): ?>
                    <dt><?= (string) ($ticket['status'] ?? '') === 'cancelled' ? 'เหตุผลในการยกเลิก' : 'หมายเหตุปิดงาน' ?></dt>
                    <dd><?= e($ticket['closure_note']) ?></dd>
                <?php endif; ?>
                <?php if ($ticket['rating_score'] > 0): ?>
                    <dt>คะแนน</dt>
                    <dd class="star-display">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <span class="star-display-star<?= $i <= (int) $ticket['rating_score'] ? ' is-active' : '' ?>" aria-hidden="true">★</span>
                        <?php endfor; ?>
                        <span class="star-display-score" aria-hidden="true"><?= e((string) $ticket['rating_score']) ?> / 5</span>
                        <span class="sr-only">คะแนน <?= e((string) $ticket['rating_score']) ?> จาก 5</span>
                    </dd>
                <?php endif; ?>
                <?php if ($ticket['rating_feedback'] !== ''): ?>
                    <dt>ความคิดเห็น</dt>
                    <dd><?= e($ticket['rating_feedback']) ?></dd>
                <?php endif; ?>
                <?php if ($ticket['rating_created_at'] !== '-'): ?>
                    <dt>ให้คะแนนเมื่อ</dt>
                    <dd><?= e(human_date($ticket['rating_created_at'])) ?></dd>
                <?php endif; ?>
            </dl>
        </section>
    <?php endif; ?>

    <!-- Comments -->
    <section class="panel-card stack-md" id="ticket-comments">
            <div class="panel-head">
                <h2 class="panel-title">ความเห็นและบทสนทนา</h2>
                <span class="badge badge-default" data-comment-count-badge data-count="<?= e((string) count($comments)) ?>"><?= e((string) count($comments)) ?> รายการ</span>
            </div>
            <?php if (!empty($workflow['canComment'])): ?>
                <form method="post" action="<?= e(url('/tickets/' . $ticket['id'] . '/comments')) ?>" class="comment-form stack-md" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="submission_token" value="<?= e((string) ($workflow['defaults']['comment_submission_token'] ?? '')) ?>">
                    <div class="field-group">
                        <label for="comment_body" class="field-label">เพิ่มความเห็น</label>
                        <textarea id="comment_body" name="body" class="input" rows="3" placeholder="พิมพ์ข้อมูลอัปเดต, คำถาม, หรือรายละเอียดเพิ่มเติม"><?= e((string) ($workflow['defaults']['comment_body'] ?? '')) ?></textarea>
                    </div>
                    <div class="field-group">
                        <label for="comment_attachments" class="field-label">แนบรูปเพิ่มเติม</label>
                        <input id="comment_attachments" name="attachments[]" type="file" class="input" accept="image/jpeg,image/png,image/webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,.pdf,.doc,.docx,.xls,.xlsx,.txt" multiple>
                        <p class="field-hint">รองรับรูปภาพและเอกสาร (PDF/Word/Excel/Text) สูงสุด 3 ไฟล์ ไฟล์ละไม่เกิน 5MB</p>
                    </div>
                    <div class="comment-form-actions">
                        <?php if (!empty($workflow['canUseInternalComment'])): ?>
                            <label class="checkbox-row checkbox-row-sm">
                                <input type="checkbox" name="is_internal" value="1"<?= in_array((string) ($workflow['defaults']['comment_is_internal'] ?? ''), ['1', 'true', 'on'], true) ? ' checked' : '' ?>>
                                <span>บันทึกภายใน (เฉพาะทีมงาน)</span>
                            </label>
                        <?php endif; ?>
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ส่งความเห็น', 'variant' => 'primary', 'icon' => 'send', 'iconPosition' => 'right']) ?>
                    </div>
                </form>
            <?php endif; ?>
            <?php
            $latestCommentId = 0;
            foreach ($comments as $__comment) {
                $latestCommentId = max($latestCommentId, (int) ($__comment['id'] ?? 0));
            }
            ?>
            <div class="stack-md" data-comment-thread data-latest-comment-id="<?= e((string) $latestCommentId) ?>" data-comment-feed-url="<?= e(url('/tickets/' . $ticket['id'] . '/comments/feed')) ?>">
                <?php if ($comments === []): ?>
                    <div data-comment-empty>
                        <?= render_partial('partials/components/empty-state', [
                            'icon' => 'message-circle',
                            'title' => 'ยังไม่มีความเห็น',
                            'description' => 'เมื่อมีการอัปเดตหรือสนทนาในงาน รายการจะปรากฏที่นี่',
                        ]) ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($comments as $comment): ?>
                    <?php $isEditingComment = (string) ($workflow['defaults']['editing_comment_id'] ?? '') === (string) $comment['id']; ?>
                    <?= render_partial('partials/tickets/comment-item', [
                        'comment' => $comment,
                        'ticketId' => (int) $ticket['id'],
                        'isEditing' => $isEditingComment,
                        'editBody' => !empty($workflow['defaults']['has_comment_body_old_input']) ? (string) ($workflow['defaults']['comment_body'] ?? '') : (string) ($comment['body'] ?? ''),
                        'editInternalChecked' => in_array((string) (!empty($workflow['defaults']['has_comment_is_internal_old_input']) ? ($workflow['defaults']['comment_is_internal'] ?? '') : ($comment['is_internal'] ? '1' : '')), ['1', 'true', 'on'], true),
                        'canUseInternalComment' => !empty($workflow['canUseInternalComment']),
                    ]) ?>
                <?php endforeach; ?>
            </div>
            <?= render_partial('partials/components/confirm-modal', [
                'id' => 'comment-delete-modal',
                'title' => 'ยืนยันการลบความเห็น',
                'icon' => 'trash',
                'lead' => 'ต้องการลบความเห็นนี้ใช่หรือไม่? การลบไม่สามารถย้อนกลับได้',
                'tone' => 'danger',
                'confirm_label' => 'ลบความเห็น',
            ]) ?>
        </section>

    <details class="panel-card collapsible" open>
        <summary class="collapsible-summary">
            <div class="collapsible-title">
                <h2 class="panel-title">ประวัติการเปลี่ยนสถานะ</h2>
                <span class="badge badge-info"><?= e((string) count($activityLogs)) ?> รายการ</span>
            </div>
            <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="collapsible-body">
            <?php if ($activityLogs === []): ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'activity',
                    'title' => 'ยังไม่มีประวัติการเปลี่ยนสถานะ',
                    'description' => 'เมื่อมีการเปลี่ยนสถานะหรือ action ใน ticket รายการจะปรากฏที่นี่',
                ]) ?>
            <?php else: ?>
                <ol class="timeline">
                    <?php foreach ($activityLogs as $log): ?>
                        <li class="timeline-item">
                            <span class="timeline-dot"></span>
                            <div class="timeline-content">
                                <?php if (($log['action_tone'] ?? 'default') !== 'default'): ?>
                                    <p class="timeline-action"><?= render_partial('partials/components/badge', ['label' => $log['action_label'], 'tone' => $log['action_tone']]) ?></p>
                                <?php else: ?>
                                    <p class="timeline-action"><?= e($log['action_label']) ?></p>
                                <?php endif; ?>
                                <p class="helper-text"><?= e($log['actor_name']) ?> · <?= e($log['actor_role']) ?> · <?= e(human_date($log['created_at'])) ?></p>
                                <?php if ($log['from_status'] !== '-' || $log['to_status'] !== '-'): ?>
                                    <p class="timeline-status"><code class="mono"><?= e($log['from_status']) ?></code> → <code class="mono"><?= e($log['to_status']) ?></code></p>
                                <?php endif; ?>
                                <?php if ($log['details'] !== ''): ?>
                                    <p class="body-text"><?= e($log['details']) ?></p>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>
    </details>
</section>

<script src="<?= e(asset('js/ticket-detail.js')) ?>" defer></script>

<style>
    /* Fallback for native anchor jump / no-JS: keep action sections clear of the sticky header + action bar */
    [id^="action-"] { scroll-margin-top: 14rem; }
</style>

<script src="<?= e(asset('js/ticket-actions.js')) ?>" defer></script>
