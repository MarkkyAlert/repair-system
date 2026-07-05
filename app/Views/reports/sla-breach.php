<?php
$filterState = $filters['selected'] ?? [];
$dimensionLabel = (string) ($filters['dimensionLabel'] ?? 'ระดับความสำคัญ');
?>
<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการตัดสินใจ',
        'title' => 'วิเคราะห์ SLA เกินกำหนด',
        'description' => 'ดูว่า SLA เกินกำหนดกระจุกตรงไหน แยก response/resolution เพื่อหา bottleneck จริง',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">เลือกมิติเพื่อจัดกลุ่ม แล้วเรียงตามจำนวนที่เกินกำหนดมากสุด</span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">เลือกมิติและกรองข้อมูล</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/sla-breach')) ?>" class="stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label for="dimension" class="field-label">แยกตามมิติ</label>
                    <select id="dimension" name="dimension" class="input">
                        <?php foreach (($filters['dimensionOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['dimension'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
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
                    <label for="priority_id" class="field-label">ระดับความสำคัญ</label>
                    <select id="priority_id" name="priority_id" class="input">
                        <?php foreach (($filters['priorityOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['priority_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
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
                    <label for="category_id" class="field-label">หมวดหมู่งาน</label>
                    <select id="category_id" name="category_id" class="input">
                        <?php foreach (($filters['categoryOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['category_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="location_id" class="field-label">สถานที่</label>
                    <select id="location_id" name="location_id" class="input">
                        <?php foreach (($filters['locationOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['location_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ดูข้อมูล', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/sla-breach', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'เกินกำหนดทั้งหมด',
            'value' => (string) ($summary['total_breached'] ?? 0),
            'meta' => 'จำนวนครั้งที่ SLA เกิน (response + resolution)',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินจากการตอบรับ',
            'value' => (string) ($summary['response_breached'] ?? 0),
            'meta' => 'รับงานช้ากว่ากำหนด',
            'tone' => 'warning',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินจากการแก้ไข',
            'value' => (string) ($summary['resolution_breached'] ?? 0),
            'meta' => 'แก้ไขเสร็จช้ากว่ากำหนด',
            'tone' => 'warning',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => '%เกินโดยรวม',
            'value' => (string) ($summary['breach_rate_label'] ?? '-'),
            'meta' => 'สัดส่วนที่เกินจากงานที่วัดผลแล้ว',
            'tone' => (string) ($summary['breach_tone'] ?? 'default'),
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>SLA เกินกำหนด แยกตาม<?= e($dimensionLabel) ?></strong>
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
                    // Export = POST + CSRF; hidden inputs พา filter + dimension ปัจจุบันไปด้วย (export ตรงกับที่เห็น)
                    $exportHidden = '';
                    foreach (['from_date', 'to_date', 'department_id', 'category_id', 'priority_id', 'location_id', 'dimension'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/sla-breach/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/sla-breach/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/sla-breach/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">จัดอันดับ bottleneck แยกตาม<?= e($dimensionLabel) ?></h2>
                <p class="field-hint">เรียงตามจำนวนที่เกินกำหนดมากสุด — ดูว่าคอขวดคือ "ตอบรับช้า" หรือ "แก้ไขช้า" ตรงกลุ่มไหน</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">SLA เกินกำหนด แยกตาม<?= e($dimensionLabel) ?> จัดอันดับตามจำนวนที่เกิน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0"><?= e($dimensionLabel) ?></th>
                        <th data-sort-col="1" data-sort-type="number">ตอบรับ เกิน</th>
                        <th data-sort-col="2" data-sort-type="number">แก้ไข เกิน</th>
                        <th data-sort-col="3" data-sort-type="number">เกินรวม</th>
                        <th data-sort-col="4" data-sort-type="number">ทันกำหนด</th>
                        <th data-sort-col="5" data-sort-type="number">%เกิน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['label']) ?></strong></td>
                            <td><span class="badge badge-<?= e((string) $row['response']['tone']) ?>"><?= e((string) $row['response']['breached']) ?></span></td>
                            <td><span class="badge badge-<?= e((string) $row['resolution']['tone']) ?>"><?= e((string) $row['resolution']['breached']) ?></span></td>
                            <td><strong><?= e((string) $row['total_breached']) ?></strong></td>
                            <td><?= e((string) $row['total_met']) ?></td>
                            <td><span class="badge badge-<?= e((string) $row['breach_tone']) ?>"><?= e((string) $row['breach_rate_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clock',
                'title' => 'ยังไม่มีข้อมูล SLA ในเงื่อนไขนี้',
                'description' => 'เมื่อมี ticket ที่มีการวัด SLA ในช่วงเวลา/เงื่อนไขที่เลือก ระบบจะสรุปจุดที่เกินกำหนดให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
