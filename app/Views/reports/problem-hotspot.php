<?php
$filterState = $filters['selected'] ?? [];
$dimensionLabel = (string) ($filters['dimensionLabel'] ?? 'แผนก');
?>
<section class="stack-lg">
    <h1 class="sr-only">พื้นที่ปัญหา (แผนก / สถานที่)</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการตัดสินใจ',
        'title' => 'พื้นที่ปัญหา (แผนก / สถานที่)',
        'description' => 'จัดอันดับแผนก/สถานที่ตามความรุนแรง — แจ้งซ่อมเยอะ · เกิน SLA เยอะ · ใช้แรงงานเยอะ',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">เลือกมิติ (แผนก/สถานที่) แล้วดูว่าพื้นที่ไหนเป็นจุดปัญหาที่ควรลงไปดูก่อน</span>
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
        <form method="get" action="<?= e(url('/reports/problem-hotspot')) ?>" class="stack-md">
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
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/problem-hotspot', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'พื้นที่ปัญหา',
            'value' => (string) ($summary['problem_areas'] ?? 0),
            'meta' => 'จากทั้งหมด ' . (int) ($summary['areas'] ?? 0) . ' พื้นที่',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'แจ้งซ่อมรวม',
            'value' => (string) ($summary['total_tickets'] ?? 0),
            'meta' => 'จำนวน ticket ในช่วงที่กรอง',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกิน SLA รวม',
            'value' => (string) ($summary['total_overdue'] ?? 0),
            'meta' => 'งานค้างที่เลยกำหนด SLA',
            'tone' => 'warning',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ชม.แรงงานรวม',
            'value' => (string) ($summary['labor_hours_label'] ?? '0'),
            'meta' => 'แรงงานที่บันทึกใน work order',
            'tone' => 'info',
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>พื้นที่ปัญหา แยกตาม<?= e($dimensionLabel) ?></strong>
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
                    // Export = POST + CSRF; hidden inputs พา filter + dimension ปัจจุบันไปด้วย
                    $exportHidden = '';
                    foreach (['from_date', 'to_date', 'department_id', 'category_id', 'dimension'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/problem-hotspot/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/problem-hotspot/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/problem-hotspot/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">จัดอันดับพื้นที่ปัญหา แยกตาม<?= e($dimensionLabel) ?></h2>
                <p class="field-hint">คะแนนพื้นที่ประเมินจาก ปริมาณแจ้งซ่อม · %เกิน SLA · ชม.แรงงาน · เวลาซ่อมเฉลี่ย — "พื้นที่ปัญหา" ถูกจัดขึ้นบนสุด</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">พื้นที่ปัญหา แยกตาม<?= e($dimensionLabel) ?> จัดอันดับตามคะแนนความรุนแรง</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0"><?= e($dimensionLabel) ?></th>
                        <th data-sort-col="1">คะแนนพื้นที่</th>
                        <th data-sort-col="2" data-sort-type="number">แจ้งซ่อม</th>
                        <th data-sort-col="3" data-sort-type="number">งานค้าง</th>
                        <th data-sort-col="4" data-sort-type="number">เกิน SLA</th>
                        <th data-sort-col="5" data-sort-type="number">%เกิน SLA</th>
                        <th data-sort-col="6" data-sort-type="number">เวลาซ่อมเฉลี่ย (ชม.)</th>
                        <th data-sort-col="7" data-sort-type="number">ชม.แรงงาน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['label']) ?></strong></td>
                            <td>
                                <span class="badge badge-<?= e((string) $row['hotspot_tone']) ?>"><?= e((string) $row['hotspot_label']) ?></span>
                                <div class="helper-text"><?= e((string) $row['hotspot_reason']) ?></div>
                            </td>
                            <td><strong><?= e((string) $row['ticket_count']) ?></strong></td>
                            <td><?= e((string) $row['open_count']) ?></td>
                            <td><?= e((string) $row['overdue_count']) ?></td>
                            <td><span class="badge badge-<?= e((string) $row['overdue_tone']) ?>"><?= e((string) $row['overdue_rate_label']) ?></span></td>
                            <td><?= e((string) $row['avg_resolution_hours_label']) ?></td>
                            <td><?= e((string) $row['labor_hours_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'map-pin',
                'title' => 'ยังไม่มีข้อมูลในเงื่อนไขนี้',
                'description' => 'เมื่อมี ticket ในช่วงเวลา/เงื่อนไขที่เลือก ระบบจะจัดอันดับพื้นที่ปัญหาให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
