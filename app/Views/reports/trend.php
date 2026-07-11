<?php
$filterState = $filters['selected'] ?? [];
$charts = $charts ?? [];
$chartHasData = static fn (array $chart): bool => !empty($chart['has_data']);
$chartCard = static function (array $chart, string $key, string $title) use ($chartHasData): string {
    ob_start(); ?>
    <section class="panel-card stack-md">
        <div class="chart-meta">
            <h2 class="panel-title"><?= e($title) ?></h2>
        </div>
        <div class="chart-shell" data-chart-shell>
            <?php if ($chartHasData($chart)): ?>
                <div class="chart-loading" data-chart-loading><?= render_partial('partials/components/skeleton') ?></div>
                <canvas class="chart-canvas" data-dashboard-chart="<?= e($key) ?>" data-chart-type="line" role="img" aria-label="<?= e($title) ?>"></canvas>
            <?php else: ?>
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'trending-up',
                    'title' => 'ยังไม่มีข้อมูลในช่วงนี้',
                    'description' => 'เมื่อมี Ticket ในช่วงเวลาที่เลือก กราฟจะแสดงที่นี่',
                ]) ?>
            <?php endif; ?>
        </div>
    </section>
    <?php return (string) ob_get_clean();
};
?>
<section class="stack-lg">
    <h1 class="sr-only">แนวโน้มงานซ่อมตามเวลา</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการตัดสินใจ',
        'title' => 'แนวโน้มงานซ่อมตามเวลา',
        'description' => 'ดูว่าปริมาณงาน · SLA · เวลาซ่อม · ความพึงพอใจ ดีขึ้นหรือแย่ลงตามช่วงเวลา',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">กราฟ "แจ้ง vs ปิด" ถ้าเส้นแจ้งอยู่เหนือเส้นปิด = งานค้างกำลังสะสม</span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">ช่วงเวลาและความละเอียด</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/trend')) ?>" class="stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label for="granularity" class="field-label">ความละเอียด</label>
                    <select id="granularity" name="granularity" class="input">
                        <?php foreach (($filters['granularityOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['granularity'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="from_date" class="field-label">วันที่เริ่มต้น</label>
                    <input id="from_date" name="from_date" type="date" class="input" value="<?= e((string) ($filterState['from_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="to_date" class="field-label">วันที่สิ้นสุด</label>
                    <input id="to_date" name="to_date" type="date" class="input" value="<?= e((string) ($filterState['to_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="department_id" class="field-label">แผนกผู้แจ้ง</label>
                    <select id="department_id" name="department_id" class="input">
                        <?php foreach (($filters['departmentOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['department_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="category_id" class="field-label">หมวดหมู่งาน</label>
                    <select id="category_id" name="category_id" class="input">
                        <?php foreach (($filters['categoryOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['category_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ดูข้อมูล', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/trend', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'แจ้งซ่อม (งวดล่าสุด)',
            'value' => (string) ($summary['created']['value'] ?? 0),
            'meta' => 'เทียบงวดก่อน ' . (string) ($summary['created']['delta_label'] ?? '—'),
            'tone' => (string) ($summary['created']['tone'] ?? 'default'),
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'SLA ตรงเวลา (งวดล่าสุด)',
            'value' => (string) ($summary['sla']['value'] ?? '-'),
            'meta' => 'เทียบงวดก่อน ' . (string) ($summary['sla']['delta_label'] ?? '—'),
            'tone' => (string) ($summary['sla']['tone'] ?? 'default'),
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เวลาซ่อมเฉลี่ย (งวดล่าสุด)',
            'value' => (string) ($summary['mttr']['value'] ?? '-'),
            'meta' => 'เทียบงวดก่อน ' . (string) ($summary['mttr']['delta_label'] ?? '—'),
            'tone' => (string) ($summary['mttr']['tone'] ?? 'default'),
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'คะแนนเฉลี่ย (งวดล่าสุด)',
            'value' => (string) ($summary['csat']['value'] ?? '-'),
            'meta' => 'เทียบงวดก่อน ' . (string) ($summary['csat']['delta_label'] ?? '—'),
            'tone' => (string) ($summary['csat']['tone'] ?? 'default'),
        ]) ?>
    </div>

    <div class="dashboard-chart-grid">
        <?= $chartCard($charts['trendVolume'] ?? [], 'trendVolume', 'ปริมาณงาน: แจ้ง vs ปิด') ?>
        <?= $chartCard($charts['trendSla'] ?? [], 'trendSla', 'SLA ตรงเวลา (%)') ?>
        <?= $chartCard($charts['trendMttr'] ?? [], 'trendMttr', 'เวลาซ่อมเฉลี่ย (ชม.)') ?>
        <?= $chartCard($charts['trendCsat'] ?? [], 'trendCsat', 'คะแนนความพึงพอใจ') ?>
    </div>

    <?php if (!empty($periods)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>ข้อมูลแนวโน้มรายช่วง</strong>
            <span class="helper-text">เลือกรูปแบบไฟล์เพื่อดาวน์โหลด (Excel · PDF · CSV)</span>
        </div>
        <div class="action-bar-right">
            <details class="export-dropdown">
                <summary class="btn btn-primary btn-md">
                    <?= lucide('download', 'button-icon') ?>
                    <span>ส่งออกรายงาน</span>
                    <?= lucide('chevron-down', 'button-icon') ?>
                </summary>
                <div class="export-dropdown-menu">
                    <?php
                    $exportHidden = '';
                    foreach (['granularity', 'from_date', 'to_date', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/trend/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/trend/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/trend/export/csv')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('download', 'button-icon') ?>
                            <span>ส่งออก CSV</span>
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </div>
    <?php endif; ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ข้อมูลแนวโน้มรายช่วง</h2>
                <p class="field-hint">"สุทธิ" = แจ้ง − ปิด (บวก = งานค้างสะสมเพิ่ม) · SLA/เวลาซ่อม/คะแนน คิดจากงานที่ปิดในช่วงนั้น</p>
            </div>
        </div>

        <?php if (!empty($periods)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ข้อมูลแนวโน้มงานซ่อมรายช่วงเวลา</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">ช่วงเวลา</th>
                        <th data-sort-col="1" data-sort-type="number">แจ้งซ่อม</th>
                        <th data-sort-col="2" data-sort-type="number">ปิดงาน</th>
                        <th data-sort-col="3" data-sort-type="number">สุทธิ</th>
                        <th data-sort-col="4" data-sort-type="number">SLA ตรงเวลา</th>
                        <th data-sort-col="5" data-sort-type="number">เวลาซ่อมเฉลี่ย (ชม.)</th>
                        <th data-sort-col="6" data-sort-type="number">คะแนนเฉลี่ย</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($periods as $period): ?>
                        <tr>
                            <td><strong><?= e((string) $period['label']) ?></strong></td>
                            <td><?= e((string) $period['created']) ?></td>
                            <td><?= e((string) $period['resolved']) ?></td>
                            <td><?= e((string) $period['net']) ?></td>
                            <td><?= e((string) $period['sla_pct_label']) ?><?php if ((int) ($period['sla_base'] ?? 0) > 0): ?> <small title="จำนวนงานที่นับ SLA (ฐานของ %)">(<?= e((string) $period['sla_base']) ?>)</small><?php endif; ?></td>
                            <td><?= e((string) $period['mttr_hours_label']) ?></td>
                            <td><?= e((string) $period['csat_label']) ?><?php if ((int) ($period['rating_count'] ?? 0) > 0): ?> <small title="จำนวนรีวิว (ฐานของคะแนน)">(<?= e((string) $period['rating_count']) ?>)</small><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'trending-up',
                'title' => 'ยังไม่มีข้อมูลในช่วงที่เลือก',
                'description' => 'เมื่อมี Ticket ในช่วงเวลาที่เลือก ระบบจะสรุปแนวโน้มให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <script id="dashboard-charts-data" type="application/json"><?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
</section>
