<?php
$filterState = $filters['selected'] ?? [];
$chartPayload = [
    'monthlyTickets' => $charts['monthlyTickets'] ?? [],
    'categoryBreakdown' => $charts['categoryBreakdown'] ?? [],
    'departmentBreakdown' => $charts['departmentBreakdown'] ?? [],
    'resolutionTrend' => $charts['resolutionTrend'] ?? [],
];
$role = (string) ($currentUser['role'] ?? 'guest');
$roleLabels = [
    'requester' => role_label_th('requester'),
    'manager' => role_label_th('manager'),
    'technician' => role_label_th('technician'),
    'admin' => role_label_th('admin'),
];
$chartHasData = static function (array $chart): bool {
    $data = $chart['data'] ?? [];
    if (!is_array($data) || $data === []) {
        return false;
    }

    return array_sum(array_map(static fn ($value): float => (float) $value, $data)) > 0;
};
$metricCount = static fn (string $key): int => max(0, (int) ($metrics[$key] ?? 0));
// primaryCta, cronHealth, urgentAlerts, chartSummaries เป็น view-model จาก controller (TicketService) แล้ว
?>
<section class="stack-lg">
    <h1 class="sr-only">Dashboard — ภาพรวมการปฏิบัติงาน</h1>
    <?php ob_start(); ?>
    <span class="badge badge-info"><?= e($roleLabels[$role] ?? $role) ?></span>
    <?= render_partial('partials/components/button', [
        'label' => $primaryCta['label'],
        'variant' => 'primary',
        'href' => $primaryCta['href'],
        'icon' => $primaryCta['icon'],
        'iconPosition' => 'right',
    ]) ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ภาพรวมการปฏิบัติงาน',
        'title' => 'สวัสดี ' . (string) ($currentUser['full_name'] ?? 'ผู้ใช้งาน'),
        'description' => 'ภาพรวมงานซ่อมและ SLA ล่าสุด',
        'actions' => $heroActions,
    ]) ?>

    <?php if (!empty($setupChecklist['items']) && empty($setupChecklist['complete']) && !setting('admin_setup_checklist_dismissed', false)): ?>
        <section class="panel-card stack-md" aria-label="รายการตั้งค่าเริ่มต้นใช้งาน">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">เริ่มต้นใช้งาน · ตั้งค่าที่แนะนำหลังติดตั้ง</h2>
                    <p class="field-hint">ทำครบเพื่อให้ระบบพร้อมใช้งานเต็มที่ — เสร็จแล้ว <?= (int) $setupChecklist['done_count'] ?>/<?= (int) $setupChecklist['total'] ?></p>
                </div>
                <form method="post" action="<?= e(url('/admin/setup-checklist/dismiss')) ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-ghost btn-sm"><span>ซ่อน</span></button>
                </form>
            </div>
            <div class="stack-md">
                <?php foreach ($setupChecklist['items'] as $item): ?>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <span style="display:inline-flex; align-items:center; gap:8px; min-width:230px;">
                            <?= lucide($item['done'] ? 'check-circle' : (string) $item['icon'], 'h-5 w-5') ?>
                            <strong><?= e((string) $item['label']) ?></strong>
                        </span>
                        <span class="badge badge-<?= $item['done'] ? 'success' : 'warning' ?>"><?= $item['done'] ? 'เสร็จแล้ว' : 'ยังไม่ได้ตั้ง' ?></span>
                        <span class="helper-text" style="flex:1; min-width:180px;"><?= e((string) $item['hint']) ?></span>
                        <?php if (!$item['done']): ?>
                            <a href="<?= e(url((string) $item['href'])) ?>" class="btn btn-ghost btn-sm"><span><?= e((string) $item['cta']) ?></span><?= lucide('chevron-right', 'button-icon') ?></a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <nav class="preset-bar" aria-label="ตัวกรองลัด Dashboard">
        <span class="helper-text">มุมมองด่วน</span>
        <a href="<?= e(url('/dashboard')) ?>" class="preset-chip<?= empty($filterState['preset']) ? ' is-active' : '' ?>"><?= lucide('layout-dashboard', 'h-3.5 w-3.5') ?> ทั้งหมด</a>
        <?php
        $presets = [
            'mine' => ['label' => 'งานของฉัน', 'icon' => 'wrench'],
            'overdue' => ['label' => 'เกิน SLA', 'icon' => 'triangle-alert'],
            'pending_approval' => ['label' => 'รออนุมัติ', 'icon' => 'clock'],
            'today' => ['label' => 'วันนี้', 'icon' => 'calendar'],
        ];
        foreach ($presets as $presetKey => $preset): ?>
            <a href="<?= e(url('/dashboard?preset=' . $presetKey)) ?>" class="preset-chip<?= (string) ($filterState['preset'] ?? '') === $presetKey ? ' is-active' : '' ?>"><?= lucide($preset['icon'], 'h-3.5 w-3.5') ?> <?= e($preset['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($cronHealth !== []): ?>
        <section class="operations-alert-strip" aria-label="สถานะ cron ที่อาจหยุดทำงาน">
            <div class="operations-alert-copy">
                <span class="operations-alert-icon"><?= lucide('refresh-cw', 'h-5 w-5') ?></span>
                <div>
                    <strong>Cron บางตัวอาจหยุดทำงาน</strong>
                    <span>ตรวจสอบ schedule บน server เพื่อให้ระบบส่งอีเมลและตรวจ SLA ได้ตามปกติ</span>
                </div>
            </div>
            <div class="operations-alert-actions">
                <?php foreach ($cronHealth as $cron): ?>
                    <a class="operations-alert-chip tone-warning" href="<?= e(url($cron['href'] ?? '/admin/email-queue')) ?>">
                        <?= lucide('clock', 'h-4 w-4') ?>
                        <span><?= e($cron['label']) ?> · <?= e($cron['last_run'] !== '' ? 'รัน ' . human_date($cron['last_run']) : 'ยังไม่เคยรัน') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (($cronFailures ?? []) !== []): ?>
        <section class="operations-alert-strip tone-danger" aria-label="cron ที่ทำงานแล้วแต่มีงานล้มเหลว">
            <div class="operations-alert-copy">
                <span class="operations-alert-icon"><?= lucide('triangle-alert', 'h-5 w-5') ?></span>
                <div>
                    <strong>งานเบื้องหลังบางรายการล้มเหลว</strong>
                    <span>Cron ทำงานแล้ว แต่มีงานที่ทำไม่สำเร็จ (ระบบบันทึกไว้ใน log แล้ว) — กรุณาตรวจสอบ</span>
                </div>
            </div>
            <div class="operations-alert-actions">
                <?php foreach (($cronFailures ?? []) as $failure): ?>
                    <a class="operations-alert-chip tone-danger" href="<?= e(url($failure['href'] ?? '/admin/email-queue')) ?>">
                        <?= lucide('triangle-alert', 'h-4 w-4') ?>
                        <span><?= e($failure['label'] ?? '-') ?> · <?= e($failure['detail'] ?? '') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($urgentAlerts !== []): ?>
        <section class="operations-alert-strip" aria-label="งานด่วนที่ควรจัดการก่อน">
            <div class="operations-alert-copy">
                <span class="operations-alert-icon"><?= lucide('triangle-alert', 'h-5 w-5') ?></span>
                <div>
                    <strong>มีงานที่ควรจัดการก่อน</strong>
                    <span>เปิดรายการเพื่อป้องกัน SLA หลุดและลดคิวงานค้าง</span>
                </div>
            </div>
            <div class="operations-alert-actions">
                <?php foreach ($urgentAlerts as $alert): ?>
                    <a class="operations-alert-chip tone-<?= e($alert['tone']) ?>" href="<?= e(url($alert['href'])) ?>">
                        <?= lucide($alert['icon'], 'h-4 w-4') ?>
                        <span><?= e($alert['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="stat-grid stat-grid-5">
        <?= render_partial('partials/components/card', [
            'title' => 'งานทั้งหมด',
            'value' => (string) $metricCount('total'),
            'meta' => 'ทุกสถานะรวมกัน',
            'tone' => 'default',
            'icon' => 'clipboard-list',
            'href' => '/tickets',
            'ariaLabel' => 'เปิดรายการ Ticket ทั้งหมด จำนวน ' . $metricCount('total') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'รออนุมัติ',
            'value' => (string) $metricCount('pendingApproval'),
            'meta' => 'รอผู้จัดการตรวจ',
            'tone' => 'warning',
            'icon' => 'clock',
            'href' => '/tickets?status=pending_approval',
            'ariaLabel' => 'เปิดรายการ Ticket รออนุมัติ จำนวน ' . $metricCount('pendingApproval') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'กำลังดำเนินการ',
            'value' => (string) $metricCount('inProgress'),
            'meta' => 'อยู่ระหว่างซ่อม',
            'tone' => 'info',
            'icon' => 'activity',
            'href' => '/tickets?status=in_progress',
            'ariaLabel' => 'เปิดรายการ Ticket กำลังดำเนินการ จำนวน ' . $metricCount('inProgress') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เสร็จเดือนนี้',
            'value' => (string) $metricCount('completedThisMonth'),
            'meta' => 'ปิดงานแล้ว',
            'tone' => 'success',
            'icon' => 'check-circle',
            'href' => '/tickets?status=completed',
            'ariaLabel' => 'เปิดรายการ Ticket ที่เสร็จแล้ว จำนวน ' . $metricCount('completedThisMonth') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินกำหนด',
            'value' => (string) $metricCount('overdue'),
            'meta' => 'ต้องเร่งจัดการ',
            'tone' => 'danger',
            'icon' => 'triangle-alert',
            'href' => '/tickets',
            'ariaLabel' => 'เปิดรายการ Ticket เกิน SLA จำนวน ' . $metricCount('overdue') . ' รายการ',
        ]) ?>
    </div>

    <section class="panel-card">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">งานที่ต้องติดตาม</h2>
                <p class="field-hint">รายการล่าสุดตามสิทธิ์และตัวกรองปัจจุบัน เปิดเพื่อจัดการต่อได้ทันที</p>
            </div>
            <?= render_partial('partials/components/button', [
                'label' => 'ดูทั้งหมด',
                'variant' => 'secondary',
                'href' => '/tickets',
                'icon' => 'arrow-right',
                'iconPosition' => 'right',
            ]) ?>
        </div>
        <?php if (!empty($recentTickets)): ?>
            <ul class="list-rows">
                <?php foreach ($recentTickets as $ticket): ?>
                    <li>
                        <a class="list-row<?= !empty($ticket['is_overdue']) ? ' is-overdue' : '' ?>" href="<?= e(url('/tickets/' . $ticket['id'])) ?>">
                            <span class="list-row-main">
                                <span class="list-row-title">
                                    <code class="mono"><?= e($ticket['ticket_no']) ?></code>
                                    <strong><?= e($ticket['title']) ?></strong>
                                </span>
                                <span class="list-row-meta"><?= e($ticket['requester_name']) ?> · <?= e($ticket['location_name']) ?> · <?= e(human_date($ticket['requested_at'])) ?></span>
                            </span>
                            <span class="list-row-side">
                                <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                                <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                                <span class="list-row-sla"><?= render_partial('partials/components/badge', ['label' => $ticket['sla_overview_label'], 'tone' => $ticket['sla_overview_tone']]) ?></span>
                                <span class="list-row-arrow"><?= lucide('chevron-right', 'h-4 w-4') ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clipboard-list',
                'title' => 'ยังไม่มีงานที่ต้องติดตามในมุมมองนี้',
                'description' => 'ลองเปลี่ยนมุมมองด่วนหรือปรับตัวกรองเพื่อดูงานที่เกี่ยวข้อง',
            ]) ?>
        <?php endif; ?>
    </section>

    <details class="collapsible" <?= ($filters['active_count'] ?? 0) > 0 ? 'open' : '' ?>>
        <summary class="collapsible-summary">
            <span class="metric-icon metric-icon-sm"><?= lucide('filter', 'h-4 w-4') ?></span>
            <div class="collapsible-summary-main">
                <span class="collapsible-title">ตัวกรองข้อมูล</span>
                <span class="collapsible-subtitle">ปรับข้อมูลตามวันที่ แผนก หมวดหมู่ และสถานะ</span>
            </div>
            <div class="collapsible-meta">
                <?php if (($filters['active_count'] ?? 0) > 0): ?>
                    <span class="badge badge-info"><?= e((string) $filters['active_count']) ?> ตัวกรอง</span>
                <?php endif; ?>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </div>
        </summary>
        <div class="collapsible-body">
        <form method="get" action="<?= e(url('/dashboard')) ?>" class="stack-md">
            <?php if ((string) ($filterState['preset'] ?? '') !== ''): ?>
                <input type="hidden" name="preset" value="<?= e((string) $filterState['preset']) ?>">
            <?php endif; ?>
            <div class="dashboard-filter-grid">
                <div class="field-group">
                    <label for="from_date" class="field-label">วันที่เริ่มต้น</label>
                    <input id="from_date" name="from_date" type="date" class="input" value="<?= e((string) ($filterState['from_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="to_date" class="field-label">วันที่สิ้นสุด</label>
                    <input id="to_date" name="to_date" type="date" class="input" value="<?= e((string) ($filterState['to_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="department_id" class="field-label">แผนก</label>
                    <select id="department_id" name="department_id" class="input">
                        <?php foreach (($filters['departmentOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['department_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="category_id" class="field-label">หมวดหมู่</label>
                    <select id="category_id" name="category_id" class="input">
                        <?php foreach (($filters['categoryOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['category_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="status" class="field-label">สถานะ</label>
                    <select id="status" name="status" class="input">
                        <?php foreach (($filters['statusOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['status'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="year" class="field-label">ปีของกราฟ</label>
                    <select id="year" name="year" class="input" aria-describedby="year-hint">
                        <?php foreach (($filters['yearOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['year'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="year-hint" class="field-hint">ใช้กับกราฟด้านล่างเท่านั้น ไม่กระทบการ์ดสรุปและรายการ</p>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรองข้อมูล', 'variant' => 'primary', 'icon' => 'filter']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/dashboard']) ?>
            </div>
        </form>
        </div>
    </details>

    <div class="dashboard-chart-grid">
        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">ปริมาณงานรายเดือน</h2>
                <span class="chart-chip">ปี <?= e(thai_year($filterState['year'] ?? date('Y'))) ?></span>
            </div>
            <?php if ($chartHasData($chartPayload['monthlyTickets'] ?? [])): ?>
                <?php $summary = $chartSummaries['monthlyTickets'] ?? ['total' => '-', 'top' => '-', 'avg' => '-']; ?>
                <p class="chart-summary">รวม <?= e($summary['total']) ?> · สูงสุด <?= e($summary['top']) ?></p>
            <?php endif; ?>
            <div class="chart-shell" data-chart-shell>
                <?php if ($chartHasData($chartPayload['monthlyTickets'] ?? [])): ?>
                    <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                    <canvas class="chart-canvas" data-dashboard-chart="monthlyTickets" data-chart-type="bar" role="img" aria-label="กราฟปริมาณงานรายเดือน รวม <?= e($summary['total'] ?? '') ?>"></canvas>
                <?php else: ?>
                    <?php ob_start(); ?>
                        <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'arrow-right', 'iconPosition' => 'right', 'size' => 'sm']) ?>
                    <?php $emptySlot = (string) ob_get_clean(); ?>
                    <?= render_partial('partials/components/empty-state', [
                        'icon' => 'bar-chart-3',
                        'title' => 'ยังไม่มีปริมาณงานในช่วงนี้',
                        'description' => 'เมื่อมี Ticket ในปีที่เลือก กราฟรายเดือนจะแสดงที่นี่',
                        'slot' => $emptySlot,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">สัดส่วนตามหมวดหมู่</h2>
                <span class="chart-chip">หมวดหมู่ยอดนิยม</span>
            </div>
            <?php if ($chartHasData($chartPayload['categoryBreakdown'] ?? [])): ?>
                <?php $summary = $chartSummaries['categoryBreakdown'] ?? ['total' => '-', 'top' => '-', 'avg' => '-']; ?>
                <p class="chart-summary">รวม <?= e($summary['total']) ?> · สูงสุด <?= e($summary['top']) ?></p>
            <?php endif; ?>
            <div class="chart-shell" data-chart-shell>
                <?php if ($chartHasData($chartPayload['categoryBreakdown'] ?? [])): ?>
                    <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                    <canvas class="chart-canvas" data-dashboard-chart="categoryBreakdown" data-chart-type="doughnut" role="img" aria-label="กราฟสัดส่วนตามหมวดหมู่ รวม <?= e($summary['total'] ?? '') ?>"></canvas>
                <?php else: ?>
                    <?php ob_start(); ?>
                        <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'arrow-right', 'iconPosition' => 'right', 'size' => 'sm']) ?>
                    <?php $emptySlot = (string) ob_get_clean(); ?>
                    <?= render_partial('partials/components/empty-state', [
                        'icon' => 'tag',
                        'title' => 'ยังไม่มีข้อมูลหมวดหมู่',
                        'description' => 'เมื่อมี Ticket ตามหมวดหมู่ ระบบจะสรุปสัดส่วนให้ที่นี่',
                        'slot' => $emptySlot,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">ปริมาณงานตามแผนก</h2>
                <span class="chart-chip">ทุกแผนก</span>
            </div>
            <?php if ($chartHasData($chartPayload['departmentBreakdown'] ?? [])): ?>
                <?php $summary = $chartSummaries['departmentBreakdown'] ?? ['total' => '-', 'top' => '-', 'avg' => '-']; ?>
                <p class="chart-summary">รวม <?= e($summary['total']) ?> · สูงสุด <?= e($summary['top']) ?></p>
            <?php endif; ?>
            <div class="chart-shell" data-chart-shell>
                <?php if ($chartHasData($chartPayload['departmentBreakdown'] ?? [])): ?>
                    <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                    <canvas class="chart-canvas" data-dashboard-chart="departmentBreakdown" data-chart-type="doughnut" role="img" aria-label="กราฟปริมาณงานตามแผนก รวม <?= e($summary['total'] ?? '') ?>"></canvas>
                <?php else: ?>
                    <?php ob_start(); ?>
                        <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'arrow-right', 'iconPosition' => 'right', 'size' => 'sm']) ?>
                    <?php $emptySlot = (string) ob_get_clean(); ?>
                    <?= render_partial('partials/components/empty-state', [
                        'icon' => 'building',
                        'title' => 'ยังไม่มีข้อมูลตามแผนก',
                        'description' => 'เมื่อมี Ticket จากแผนกต่าง ๆ ระบบจะแสดงสัดส่วนให้ที่นี่',
                        'slot' => $emptySlot,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">เวลาแก้ไขเฉลี่ย</h2>
                <span class="chart-chip">ชั่วโมง</span>
            </div>
            <?php if ($chartHasData($chartPayload['resolutionTrend'] ?? [])): ?>
                <?php $summary = $chartSummaries['resolutionTrend'] ?? ['total' => '-', 'top' => '-', 'avg' => '-']; ?>
                <p class="chart-summary">เฉลี่ยรวม <?= e($summary['avg']) ?> · สูงสุด <?= e($summary['top']) ?></p>
            <?php endif; ?>
            <div class="chart-shell" data-chart-shell>
                <?php if ($chartHasData($chartPayload['resolutionTrend'] ?? [])): ?>
                    <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                    <canvas class="chart-canvas" data-dashboard-chart="resolutionTrend" data-chart-type="line" role="img" aria-label="กราฟเวลาแก้ไขเฉลี่ย ค่าเฉลี่ยสูงสุด <?= e($summary['top'] ?? '') ?>"></canvas>
                <?php else: ?>
                    <?php ob_start(); ?>
                        <?= render_partial('partials/components/button', ['label' => 'ดูงานที่กำลังดำเนินการ', 'variant' => 'secondary', 'href' => '/tickets?status=in_progress', 'icon' => 'arrow-right', 'iconPosition' => 'right', 'size' => 'sm']) ?>
                    <?php $emptySlot = (string) ob_get_clean(); ?>
                    <?= render_partial('partials/components/empty-state', [
                        'icon' => 'clock',
                        'title' => 'ยังไม่มีเวลาแก้ไขเฉลี่ย',
                        'description' => 'เมื่อมีงานที่แก้ไขเสร็จ ระบบจะคำนวณแนวโน้มให้ที่นี่',
                        'slot' => $emptySlot,
                    ]) ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php if (is_manager_or_admin($role)): ?>
        <?php $csat = $csat ?? ['total_ratings' => 0, 'average_label' => '0.0', 'positive_percent' => 0, 'distribution' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]]; ?>
        <section class="panel-card stack-md">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">ความพึงพอใจของผู้แจ้ง (CSAT)</h2>
                    <p class="field-hint">คะแนนเฉลี่ยจากผู้แจ้งหลังปิดงาน · ใช้ตัวกรองเดียวกับ dashboard</p>
                </div>
                <span class="badge badge-info"><?= e((string) $csat['total_ratings']) ?> รีวิว</span>
            </div>

            <?php if ((int) $csat['total_ratings'] === 0): ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'message-circle',
                    'title' => 'ยังไม่มี ticket ที่ได้รับคะแนน',
                    'description' => 'หลังจากผู้แจ้งกดปิดงาน + ให้คะแนน ระบบจะสรุปสัดส่วนความพึงพอใจให้ที่นี่',
                ]) ?>
            <?php else: ?>
                <div class="csat-grid">
                    <div class="csat-summary-card">
                        <div class="csat-score-line">
                            <span class="csat-score-number"><?= e($csat['average_label']) ?></span>
                            <span class="csat-score-suffix">/ 5</span>
                        </div>
                        <p class="helper-text">คะแนนเฉลี่ย · บวก (4-5⭐) <?= e((string) $csat['positive_percent']) ?>%</p>
                    </div>
                    <div class="csat-distribution">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <?php
                            $count = (int) ($csat['distribution'][$star] ?? 0);
                            $percent = (int) $csat['total_ratings'] > 0 ? (int) round($count * 100 / (int) $csat['total_ratings']) : 0;
                            ?>
                            <div class="csat-row">
                                <span class="csat-row-label"><?= str_repeat('⭐', $star) ?></span>
                                <span class="csat-row-bar" aria-hidden="true"><span style="width:<?= $percent ?>%"></span></span>
                                <span class="csat-row-count"><?= e((string) $count) ?> · <?= $percent ?>%</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <details class="collapsible">
        <summary class="collapsible-summary">
            <span class="metric-icon metric-icon-sm"><?= lucide('bar-chart-3', 'h-4 w-4') ?></span>
            <div class="collapsible-summary-main">
                <span class="collapsible-title">รายงานเชิงลึก</span>
                <span class="collapsible-subtitle">5 อันดับช่างและหมวดหมู่ที่พบบ่อย</span>
            </div>
            <div class="collapsible-meta">
                <span class="badge badge-info">5 อันดับ</span>
                <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
            </div>
        </summary>
        <div class="collapsible-body content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">5 อันดับช่างผู้ปฏิบัติงาน</h2>
                <span class="badge badge-info">ปริมาณ + คะแนน + เกินกำหนด</span>
            </div>
            <?php if (!empty($highlights['topTechnicians'])): ?>
                <div class="table-wrap">
                    <table class="insight-table leaderboard-table" data-mobile-card>
                        <caption class="sr-only">5 อันดับช่างผู้ปฏิบัติงาน</caption>
                        <thead>
                        <tr>
                            <th>ช่างเทคนิค</th>
                            <th>จำนวนงาน</th>
                            <th>คะแนนเฉลี่ย</th>
                            <th>เกินกำหนด</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($highlights['topTechnicians'] ?? []) as $row): ?>
                            <tr>
                                <td data-label="ช่างเทคนิค"><?= e($row['name']) ?></td>
                                <td data-label="จำนวนงาน" class="leaderboard-number"><?= e((string) $row['ticket_count']) ?></td>
                                <td data-label="คะแนนเฉลี่ย"><?= e((string) $row['avg_rating_label']) ?></td>
                                <td data-label="เกินกำหนด"><?= e((string) $row['overdue_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'bar-chart-3',
                    'title' => 'ยังไม่มีอันดับช่างเทคนิค',
                    'description' => 'เมื่อมีงานที่มอบหมายและมีประวัติคะแนนหรือเกินกำหนด ระบบจะแสดงลำดับให้อัตโนมัติ',
                ]) ?>
            <?php endif; ?>
        </section>

        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">5 อันดับหมวดหมู่ที่พบบ่อย</h2>
                <span class="badge badge-warning">ปัญหาที่เกิดซ้ำ</span>
            </div>
            <?php if (!empty($highlights['topCategories'])): ?>
                <div class="table-wrap">
                    <table class="insight-table leaderboard-table" data-mobile-card>
                        <caption class="sr-only">5 อันดับหมวดหมู่ที่พบบ่อย</caption>
                        <thead>
                        <tr>
                            <th>หมวดหมู่</th>
                            <th>จำนวนงาน</th>
                            <th>เกินกำหนด</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($highlights['topCategories'] ?? []) as $row): ?>
                            <tr>
                                <td data-label="หมวดหมู่"><?= e($row['name']) ?></td>
                                <td data-label="จำนวนงาน" class="leaderboard-number"><?= e((string) $row['ticket_count']) ?></td>
                                <td data-label="เกินกำหนด"><?= e((string) $row['overdue_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'bar-chart-3',
                    'title' => 'ยังไม่มีข้อมูลหมวดที่พบบ่อย',
                    'description' => 'เมื่อมีการแจ้งงานตามหมวดต่าง ๆ ระบบจะสรุปหมวดหมู่ที่พบบ่อยให้ที่นี่',
                ]) ?>
            <?php endif; ?>
        </section>
        </div>
    </details>

    <script id="dashboard-charts-data" type="application/json"><?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
</section>
