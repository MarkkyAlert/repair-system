<?php
$filterState = $filters['selected'] ?? [];
$rowCount = count($rows ?? []);
$rowsMeta = $rowsMeta ?? ['displayed' => $rowCount, 'limit' => 500, 'capped' => false];
?>
<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการตัดสินใจ',
        'title' => 'รายงานสุขภาพทรัพย์สิน',
        'description' => 'จัดอันดับทรัพย์สินตามความเสี่ยง พร้อมคำแนะนำว่าควรซ่อมต่อหรือเปลี่ยน',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">ดูภาพรวมทั้งหมด ปริมาณงาน SLA และ export รายงานรวมได้ที่หน้ารายงานหลัก</span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">กรองทรัพย์สินและช่วงเวลา</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/asset-reliability')) ?>" class="stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label for="from_date" class="field-label">วันที่เริ่มต้น</label>
                    <input id="from_date" name="from_date" type="date" class="input" value="<?= e((string) ($filterState['from_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="to_date" class="field-label">วันที่สิ้นสุด</label>
                    <input id="to_date" name="to_date" type="date" class="input" value="<?= e((string) ($filterState['to_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="asset_category_id" class="field-label">หมวดหมู่ทรัพย์สิน</label>
                    <select id="asset_category_id" name="asset_category_id" class="input">
                        <?php foreach (($filters['categoryOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['asset_category_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
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
                <div class="field-group">
                    <label for="asset_status" class="field-label">สถานะทรัพย์สิน</label>
                    <select id="asset_status" name="asset_status" class="input">
                        <?php foreach (($filters['statusOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['asset_status'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรองข้อมูล', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/asset-reliability', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-5 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'ทรัพย์สินที่แจ้งซ่อม',
            'value' => (string) ($summary['assets'] ?? 0),
            'meta' => 'จำนวนทรัพย์สินในช่วงที่กรอง',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ควรเปลี่ยน',
            'value' => (string) ($summary['replace'] ?? 0),
            'meta' => 'เสี่ยงสูง พิจารณาเปลี่ยนอุปกรณ์',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เฝ้าระวัง',
            'value' => (string) ($summary['watch'] ?? 0),
            'meta' => 'มีสัญญาณเสี่ยง ควรติดตาม',
            'tone' => 'warning',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'Downtime รวม (ชม.)',
            'value' => (string) ($summary['downtimeHoursLabel'] ?? '0'),
            'meta' => 'เวลาแก้ไขสะสมทั้งหมด',
            'tone' => 'info',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ชม.แรงงานรวม',
            'value' => (string) ($summary['laborHoursLabel'] ?? '0'),
            'meta' => 'แรงงานที่บันทึกใน work order',
            'tone' => 'warning',
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>รายงานสุขภาพทรัพย์สินตามตัวกรองปัจจุบัน</strong>
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
                    // Export = POST + CSRF (กัน CSRF-via-GET); hidden inputs พา filter ปัจจุบันไปด้วย
                    $exportHidden = '';
                    foreach (['from_date', 'to_date', 'asset_category_id', 'location_id', 'asset_status'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/asset-reliability/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/asset-reliability/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/asset-reliability/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">ทรัพย์สินเรียงตามความเสี่ยง</h2>
                <p class="field-hint">คะแนนสุขภาพประเมินจากความถี่เสีย · อายุ · ประกัน · MTBF · เวลาซ่อม · สถานะ — "ควรเปลี่ยน" ถูกจัดขึ้นบนสุด</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">รายงานสุขภาพทรัพย์สิน จัดอันดับตามคะแนนความเสี่ยง</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0" data-sort-type="number">อันดับ</th>
                        <th data-sort-col="1">ทรัพย์สิน</th>
                        <th data-sort-col="2">หมวดหมู่</th>
                        <th data-sort-col="3">สถานที่</th>
                        <th data-sort-col="4">สุขภาพ</th>
                        <th data-sort-col="5" data-sort-type="number">จำนวนครั้ง</th>
                        <th data-sort-col="6" data-sort-type="date">ครั้งล่าสุด</th>
                        <th data-sort-col="7" data-sort-type="number">MTBF (วัน)</th>
                        <th data-sort-col="8" data-sort-type="number">เวลาซ่อมเฉลี่ย (ชม.)</th>
                        <th data-sort-col="9" data-sort-type="number">Downtime (ชม.)</th>
                        <th data-sort-col="10" data-sort-type="number">ชม.แรงงาน</th>
                        <th data-sort-col="11" data-sort-type="number">อายุ (ปี)</th>
                        <th data-sort-col="12">ประกัน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $index => $asset): ?>
                        <tr>
                            <td class="leaderboard-number"><?= e((string) ($index + 1)) ?></td>
                            <td>
                                <a href="<?= e(url($asset['detail_url'])) ?>" class="leaderboard-number"><?= e($asset['asset_code']) ?></a>
                                <div class="helper-text"><?= e($asset['name']) ?></div>
                                <div class="helper-text"><span class="badge badge-<?= e($asset['status_tone']) ?>"><?= e($asset['status_label']) ?></span></div>
                            </td>
                            <td><?= e($asset['category_name']) ?></td>
                            <td><?= e($asset['location_name']) ?></td>
                            <td>
                                <span class="badge badge-<?= e($asset['health_tone']) ?>"><?= e($asset['health_label']) ?></span>
                                <div class="helper-text"><?= e($asset['health_reason']) ?></div>
                            </td>
                            <td><strong><?= e((string) $asset['failure_count']) ?></strong> ครั้ง</td>
                            <td><?= e($asset['last_failure']) ?></td>
                            <td><?= e($asset['mtbf_days_label']) ?></td>
                            <td><?= e($asset['avg_resolution_hours_label']) ?></td>
                            <td><?= e($asset['downtime_hours_label']) ?></td>
                            <td><?= e($asset['labor_hours_label']) ?></td>
                            <td><?= e($asset['age_label']) ?></td>
                            <td><span class="badge badge-<?= e($asset['warranty_tone']) ?>"><?= e($asset['warranty_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'wrench',
                'title' => 'ยังไม่มีทรัพย์สินที่แจ้งซ่อมในเงื่อนไขนี้',
                'description' => 'เมื่อมี ticket ที่ผูกกับทรัพย์สินในช่วงเวลา/เงื่อนไขที่เลือก ระบบจะจัดอันดับสุขภาพให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
