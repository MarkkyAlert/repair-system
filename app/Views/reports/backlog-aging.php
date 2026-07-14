<?php
$filterState = $filters['selected'] ?? [];
$dimensionLabel = (string) ($filters['dimensionLabel'] ?? 'ระดับความสำคัญ');
?>
<section class="stack-lg">
    <h1 class="sr-only">งานค้างตามอายุ (Backlog Aging)</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการจัดการงาน',
        'title' => 'งานค้างตามอายุ (Backlog Aging)',
        'description' => 'งานที่ยังไม่ปิด ณ ปัจจุบัน แยกตามช่วงอายุ — เห็นว่าอะไรค้างนานและกระจุกตรงไหน',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">"งานค้าง ≥30 วัน" คือกลุ่มที่ควรรีบเคลียร์ก่อน — เรียงขึ้นบนสุดให้แล้ว</span>
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
        <form method="get" action="<?= e(url('/reports/backlog-aging')) ?>" class="stack-md">
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
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/backlog-aging', 'icon' => 'x']) ?>
                <span class="helper-text">งานค้าง ณ ปัจจุบัน (ทุกสถานะที่ยังไม่ปิด) — ไม่ขึ้นกับช่วงวันที่</span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-5 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'งานค้างทั้งหมด',
            'value' => (string) ($summary['total'] ?? 0),
            'meta' => 'ทุกสถานะที่ยังไม่ปิด · เก่าสุด ' . (string) ($summary['oldest_label'] ?? '-'),
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ค้าง 0-2 วัน',
            'value' => (string) ($summary['bucket_0_3'] ?? 0),
            'meta' => 'งานใหม่ อยู่ในกำหนดปกติ',
            'tone' => 'success',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ค้าง 3-6 วัน',
            'value' => (string) ($summary['bucket_3_7'] ?? 0),
            'meta' => 'เริ่มค้าง ควรติดตาม',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ค้าง 7-29 วัน',
            'value' => (string) ($summary['bucket_7_30'] ?? 0),
            'meta' => 'ค้างนาน เสี่ยงบานปลาย',
            'tone' => 'warning',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ค้าง ≥30 วัน',
            'value' => (string) ($summary['bucket_30_plus'] ?? 0),
            'meta' => 'ค้างเรื้อรัง ต้องเคลียร์ด่วน',
            'tone' => 'danger',
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>งานค้างตามอายุ แยกตาม<?= e($dimensionLabel) ?></strong>
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
                    foreach (['dimension', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/backlog-aging/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/backlog-aging/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/backlog-aging/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">งานค้างตามอายุ แยกตาม<?= e($dimensionLabel) ?></h2>
                <p class="field-hint">แต่ละแถวคือจำนวนงานค้างในแต่ละช่วงอายุ — เรียงกลุ่มที่ค้าง ≥30 วันมากสุดขึ้นบน</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">งานค้างตามอายุ แยกตาม<?= e($dimensionLabel) ?></caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0"><?= e($dimensionLabel) ?></th>
                        <th data-sort-col="1" data-sort-type="number">0-2 วัน</th>
                        <th data-sort-col="2" data-sort-type="number">3-6 วัน</th>
                        <th data-sort-col="3" data-sort-type="number">7-29 วัน</th>
                        <th data-sort-col="4" data-sort-type="number">≥30 วัน</th>
                        <th data-sort-col="5" data-sort-type="number">รวม</th>
                        <th data-sort-col="6" data-sort-type="number">เก่าสุด (วัน)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['label']) ?></strong></td>
                            <td><?= e((string) $row['bucket_0_3']) ?></td>
                            <td><?= e((string) $row['bucket_3_7']) ?></td>
                            <td><?php if ($row['bucket_7_30'] > 0): ?><span class="badge badge-<?= e((string) $row['warn_tone']) ?>"><?= e((string) $row['bucket_7_30']) ?></span><?php else: ?>0<?php endif; ?></td>
                            <td><?php if ($row['bucket_30_plus'] > 0): ?><span class="badge badge-<?= e((string) $row['over30_tone']) ?>"><?= e((string) $row['bucket_30_plus']) ?></span><?php else: ?>0<?php endif; ?></td>
                            <td><strong><?= e((string) $row['total']) ?></strong></td>
                            <td><?= e((string) $row['oldest_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'check-circle',
                'title' => 'ไม่มีงานค้างในเงื่อนไขนี้',
                'description' => 'เมื่อมี Ticket ที่ยังไม่ปิดในเงื่อนไขที่เลือก ระบบจะสรุปตามช่วงอายุให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
