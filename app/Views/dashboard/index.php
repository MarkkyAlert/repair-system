<?php
$filterState = $filters['selected'] ?? [];
$chartPayload = [
    'monthlyTickets' => $charts['monthlyTickets'] ?? [],
    'categoryBreakdown' => $charts['categoryBreakdown'] ?? [],
    'departmentBreakdown' => $charts['departmentBreakdown'] ?? [],
    'resolutionTrend' => $charts['resolutionTrend'] ?? [],
];
?>
<section class="stack-lg">
    <?php ob_start(); ?>
    <span class="badge badge-info"><?= e($currentUser['role'] ?? 'guest') ?></span>
    <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'arrow-right', 'iconPosition' => 'right']) ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ภาพรวมการปฏิบัติงาน',
        'title' => 'สวัสดี ' . (string) ($currentUser['full_name'] ?? 'ผู้ใช้งาน'),
        'description' => 'ภาพรวมงานซ่อมและ SLA แบบ real-time',
        'actions' => $heroActions,
    ]) ?>

    <details class="collapsible" <?= ($filters['active_count'] ?? 0) > 0 ? 'open' : '' ?>>
        <summary class="collapsible-summary">
            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('filter', 'h-4 w-4') ?></span>
            <div class="collapsible-summary-main">
                <span class="collapsible-title">ตัวกรองข้อมูล</span>
                <span class="collapsible-subtitle">วันที่, แผนก, หมวดหมู่, สถานะ, ปีของกราฟ — กรองทุก KPI และกราฟใน dashboard</span>
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
                    <select id="year" name="year" class="input">
                        <?php foreach (($filters['yearOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['year'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรองข้อมูล', 'variant' => 'primary', 'icon' => 'filter']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/dashboard']) ?>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-5">
        <?= render_partial('partials/components/card', [
            'title' => 'งานทั้งหมด',
            'value' => (string) ($metrics['total'] ?? 0),
            'meta' => 'ตามสิทธิ์ของคุณ',
            'tone' => 'default',
            'icon' => 'clipboard-list',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'รออนุมัติ',
            'value' => (string) ($metrics['pendingApproval'] ?? 0),
            'meta' => 'รอตรวจสอบ',
            'tone' => 'warning',
            'icon' => 'clock',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'กำลังดำเนินการ',
            'value' => (string) ($metrics['inProgress'] ?? 0),
            'meta' => 'งานช่างเทคนิค',
            'tone' => 'info',
            'icon' => 'activity',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เสร็จเดือนนี้',
            'value' => (string) ($metrics['completedThisMonth'] ?? 0),
            'meta' => 'เดือนปัจจุบัน',
            'tone' => 'success',
            'icon' => 'check-circle',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินกำหนด',
            'value' => (string) ($metrics['overdue'] ?? 0),
            'meta' => 'ต้องเร่งดำเนินการ',
            'tone' => 'danger',
            'icon' => 'triangle-alert',
        ]) ?>
    </div>

    <div class="dashboard-chart-grid">
        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">ปริมาณงานรายเดือน</h2>
                <span class="chart-chip">ปี <?= e((string) ($filterState['year'] ?? date('Y'))) ?></span>
            </div>
            <div class="chart-shell" data-chart-shell>
                <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                <canvas class="chart-canvas" data-dashboard-chart="monthlyTickets" data-chart-type="bar"></canvas>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">สัดส่วนตามหมวดหมู่</h2>
                <span class="chart-chip">Top categories</span>
            </div>
            <div class="chart-shell" data-chart-shell>
                <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                <canvas class="chart-canvas" data-dashboard-chart="categoryBreakdown" data-chart-type="doughnut"></canvas>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">ปริมาณงานตามแผนก</h2>
                <span class="chart-chip">ทุกแผนก</span>
            </div>
            <div class="chart-shell" data-chart-shell>
                <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                <canvas class="chart-canvas" data-dashboard-chart="departmentBreakdown" data-chart-type="doughnut"></canvas>
            </div>
        </section>

        <section class="panel-card stack-md">
            <div class="chart-meta">
                <h2 class="panel-title">เวลาแก้ไขเฉลี่ย</h2>
                <span class="chart-chip">ชั่วโมง</span>
            </div>
            <div class="chart-shell" data-chart-shell>
                <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                <canvas class="chart-canvas" data-dashboard-chart="resolutionTrend" data-chart-type="line"></canvas>
            </div>
        </section>
    </div>

    <details class="collapsible">
        <summary class="collapsible-summary">
            <span class="metric-icon" style="width:36px;height:36px;flex:0 0 36px"><?= lucide('bar-chart-3', 'h-4 w-4') ?></span>
            <div class="collapsible-summary-main">
                <span class="collapsible-title">รายงานเชิงลึก (Top performers)</span>
                <span class="collapsible-subtitle">5 อันดับช่างและหมวดหมู่ที่พบบ่อย</span>
            </div>
            <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="collapsible-body content-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <h2 class="panel-title">5 อันดับช่างผู้ปฏิบัติงาน</h2>
                <span class="badge badge-info">ปริมาณ + คะแนน + เกินกำหนด</span>
            </div>
            <?php if (!empty($highlights['topTechnicians'])): ?>
                <div class="table-wrap">
                    <table class="insight-table">
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
                                <td><?= e($row['name']) ?></td>
                                <td class="leaderboard-number"><?= e((string) $row['ticket_count']) ?></td>
                                <td><?= e((string) $row['avg_rating_label']) ?></td>
                                <td><?= e((string) $row['overdue_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'bar-chart-3',
                    'title' => 'ยังไม่มีข้อมูล technician leaderboard',
                    'description' => 'เมื่อมีงานที่ถูก assign และมีประวัติ rating/overdue ระบบจะแสดงลำดับให้อัตโนมัติ',
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
                    <table class="insight-table">
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
                                <td><?= e($row['name']) ?></td>
                                <td class="leaderboard-number"><?= e((string) $row['ticket_count']) ?></td>
                                <td><?= e((string) $row['overdue_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'bar-chart-3',
                    'title' => 'ยังไม่มีข้อมูลหมวดที่พบบ่อย',
                    'description' => 'เมื่อมีการแจ้งงานตามหมวดต่าง ๆ ระบบจะสรุป top categories ให้ที่นี่',
                ]) ?>
            <?php endif; ?>
        </section>
        </div>
    </details>

    <section class="panel-card">
        <div class="panel-head">
            <h2 class="panel-title">งานล่าสุดที่คุณเข้าถึงได้</h2>
            <?= render_partial('partials/components/button', [
                'label' => 'ดูทั้งหมด',
                'variant' => 'ghost',
                'href' => '/tickets',
                'icon' => 'arrow-right',
                'iconPosition' => 'right',
            ]) ?>
        </div>
        <?php if (!empty($recentTickets)): ?>
            <ul class="list-rows">
                <?php foreach ($recentTickets as $ticket): ?>
                    <li class="list-row<?= !empty($ticket['is_overdue']) ? ' is-overdue' : '' ?>" data-href="<?= e(url('/tickets/' . $ticket['id'])) ?>">
                        <div class="list-row-main">
                            <div class="list-row-title">
                                <code class="mono"><?= e($ticket['ticket_no']) ?></code>
                                <strong><?= e($ticket['title']) ?></strong>
                            </div>
                            <p class="list-row-meta"><?= e($ticket['requester_name']) ?> · <?= e($ticket['location_name']) ?> · <?= e(human_date($ticket['requested_at'])) ?></p>
                        </div>
                        <div class="list-row-side">
                            <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                            <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                            <?= render_partial('partials/components/badge', ['label' => $ticket['sla_overview_label'], 'tone' => $ticket['sla_overview_tone']]) ?>
                            <span class="list-row-arrow"><?= lucide('chevron-right', 'h-4 w-4') ?></span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clipboard-list',
                'title' => 'ยังไม่มีงานที่เห็น',
                'description' => 'เมื่อมีงานตามสิทธิ์ของคุณ รายการล่าสุดจะปรากฏที่นี่',
            ]) ?>
        <?php endif; ?>
    </section>

    <script id="dashboard-charts-data" type="application/json"><?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
</section>

<script>
(() => {
    document.querySelectorAll('.list-row[data-href], .ticket-row[data-href]').forEach((row) => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('a,button,input,select,textarea')) return;
            window.location.href = row.dataset.href;
        });
        row.style.cursor = 'pointer';
    });
})();
</script>
