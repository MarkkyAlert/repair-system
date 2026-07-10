<?php
$filterState = $filters['selected'] ?? [];
$kpis = $kpis ?? [];
?>
<section class="stack-lg">
    <h1 class="sr-only">สรุปผู้บริหาร (เทียบงวด)</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'สรุปสำหรับผู้บริหาร',
        'title' => 'สรุปผู้บริหาร (เทียบงวด)',
        'description' => 'KPI หลักของงวดนี้เทียบกับงวดก่อน พร้อมทิศทาง ↑↓ — พร้อมพรีเซนต์ได้ทันที',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">งวดนี้ <strong><?= e((string) ($period['this'] ?? '-')) ?></strong> · เทียบงวดก่อน <strong><?= e((string) ($period['prev'] ?? '-')) ?></strong></span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">เลือกงวดและกรองข้อมูล</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/executive')) ?>" class="stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label for="preset" class="field-label">งวดเวลา</label>
                    <select id="preset" name="preset" class="input">
                        <?php foreach (($filters['presetOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['preset'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="from_date" class="field-label">วันที่เริ่มต้น (กำหนดเอง)</label>
                    <input id="from_date" name="from_date" type="date" class="input" value="<?= e((string) ($filterState['from_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="to_date" class="field-label">วันที่สิ้นสุด (กำหนดเอง)</label>
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
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/executive', 'icon' => 'x']) ?>
                <span class="helper-text">"งวดก่อน" คือช่วงยาวเท่ากันที่อยู่ก่อนหน้า</span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-3 report-stat-scroll">
        <?php foreach ($kpis as $kpi): ?>
            <?= render_partial('partials/components/card', [
                'title' => (string) ($kpi['label'] ?? '-'),
                'value' => (string) ($kpi['value_label'] ?? '-'),
                'meta' => (string) ($kpi['delta_label'] ?? '—') . ' (' . (string) ($kpi['pct_label'] ?? '—') . ') · งวดก่อน ' . (string) ($kpi['prev_value_label'] ?? '-'),
                'tone' => (string) ($kpi['tone'] ?? 'default'),
            ]) ?>
        <?php endforeach; ?>
    </div>

    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>สรุป KPI เทียบงวด</strong>
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
                    foreach (['preset', 'from_date', 'to_date', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/executive/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/executive/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/executive/export/csv')) ?>" class="export-dropdown-form">
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
</section>
