<?php
$filterState = $filters['selected'] ?? [];
$dimensionLabel = (string) ($filters['dimensionLabel'] ?? 'ช่าง');
?>
<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการควบคุมคุณภาพ',
        'title' => 'งานเปิดซ้ำ / ปิดจบรอบเดียว (First-Time-Fix)',
        'description' => 'งานที่ปิดแล้วถูกเปิดซ้ำกี่ % — เปิดซ้ำเยอะ = แก้ไม่จบ/คุณภาพงานต่ำ',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">เรียง %เปิดซ้ำมากสุดขึ้นบน — จุดที่งานเด้งกลับบ่อยควรลงไปหา root cause</span>
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
        <form method="get" action="<?= e(url('/reports/reopen-rate')) ?>" class="stack-md">
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
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/reopen-rate', 'icon' => 'x']) ?>
                <span class="helper-text">นับจากงานที่ "ปิด" ในช่วงที่เลือก (ว่าง= ทั้งหมด)</span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'งานที่ปิด',
            'value' => (string) ($summary['resolved'] ?? 0),
            'meta' => 'จำนวนงานที่ปิดในช่วงที่กรอง',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เปิดซ้ำ',
            'value' => (string) ($summary['reopened'] ?? 0),
            'meta' => 'งานที่ถูกเปิดกลับมาแก้ใหม่',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => '%เปิดซ้ำ',
            'value' => (string) ($summary['reopen_rate_label'] ?? '-'),
            'meta' => 'สัดส่วนงานที่เด้งกลับ',
            'tone' => (string) ($summary['reopen_tone'] ?? 'default'),
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => '%ปิดจบรอบเดียว',
            'value' => (string) ($summary['ftf_label'] ?? '-'),
            'meta' => 'First-Time-Fix — ยิ่งสูงยิ่งดี',
            'tone' => 'success',
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>งานเปิดซ้ำ แยกตาม<?= e($dimensionLabel) ?></strong>
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
                    foreach (['dimension', 'from_date', 'to_date', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/reopen-rate/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/reopen-rate/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/reopen-rate/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">งานเปิดซ้ำ แยกตาม<?= e($dimensionLabel) ?></h2>
                <p class="field-hint">%เปิดซ้ำ = งานเปิดซ้ำ ÷ งานที่ปิด · %ปิดจบรอบเดียว = 100 − %เปิดซ้ำ — เรียง %เปิดซ้ำมากสุดขึ้นบน</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">งานเปิดซ้ำ แยกตาม<?= e($dimensionLabel) ?> จัดอันดับตาม %เปิดซ้ำ</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0"><?= e($dimensionLabel) ?></th>
                        <th data-sort-col="1" data-sort-type="number">งานที่ปิด</th>
                        <th data-sort-col="2" data-sort-type="number">เปิดซ้ำ</th>
                        <th data-sort-col="3" data-sort-type="number">%เปิดซ้ำ</th>
                        <th data-sort-col="4" data-sort-type="number">%ปิดจบรอบเดียว</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['label']) ?></strong></td>
                            <td><?= e((string) $row['resolved']) ?></td>
                            <td><?php if ($row['reopened'] > 0): ?><strong><?= e((string) $row['reopened']) ?></strong><?php else: ?>0<?php endif; ?></td>
                            <td><span class="badge badge-<?= e((string) $row['reopen_tone']) ?>"><?= e((string) $row['reopen_rate_label']) ?></span></td>
                            <td><?= e((string) $row['ftf_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'check-circle',
                'title' => 'ยังไม่มีงานที่ปิดในเงื่อนไขนี้',
                'description' => 'เมื่อมี Ticket ที่ถูกปิดในช่วง/เงื่อนไขที่เลือก ระบบจะสรุปอัตราเปิดซ้ำให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
