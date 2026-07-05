<?php
$filterState = $filters['selected'] ?? [];
?>
<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการบริหารทีม',
        'title' => 'ผลงานและภาระงานทีมช่าง',
        'description' => 'ดูโหลดงานปัจจุบันของช่างแต่ละคน คู่กับผลงานในช่วงที่เลือก เพื่อเกลี่ยงานและติดตามคุณภาพ',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">เรียงช่างที่งานค้างมากสุดขึ้นบน — ช่วยหาว่าใครโหลดเกิน/ใครรับเพิ่มได้</span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">ช่วงเวลาผลงาน</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/technician-performance')) ?>" class="stack-md">
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
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/technician-performance', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'ช่างทั้งหมด',
            'value' => (string) ($summary['technicians'] ?? 0),
            'meta' => 'ช่างที่ยังใช้งานในระบบ',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'งานค้างรวม (ตอนนี้)',
            'value' => (string) ($summary['open_now'] ?? 0),
            'meta' => 'งานที่ยังไม่ปิด ณ ปัจจุบัน',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ปิดในช่วงนี้',
            'value' => (string) ($summary['resolved'] ?? 0),
            'meta' => 'งานที่ปิดตามช่วงที่กรอง',
            'tone' => 'success',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'SLA ตรงเวลา (ทีม)',
            'value' => (string) ($summary['sla_on_time_label'] ?? '-'),
            'meta' => 'สัดส่วนงานที่ปิดทัน SLA',
            'tone' => (string) ($summary['sla_on_time_tone'] ?? 'default'),
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>ผลงานและภาระงานช่างรายคน</strong>
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
                    // Export = POST + CSRF; hidden inputs พา filter ปัจจุบันไปด้วย
                    $exportHidden = '';
                    foreach (['from_date', 'to_date', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/technician-performance/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/technician-performance/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/technician-performance/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">ช่างรายคน — โหลดปัจจุบัน + ผลงานในช่วง</h2>
                <p class="field-hint">งานค้าง · ค้างเก่าสุด · สัดส่วนโหลด = ณ ตอนนี้ (ไม่ขึ้นกับช่วงวันที่) — ส่วนที่เหลือคือผลงานตามช่วงที่กรอง</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> คน</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ผลงานและภาระงานทีมช่าง จัดอันดับตามงานค้างปัจจุบัน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">ช่าง</th>
                        <th data-sort-col="1" data-sort-type="number">งานค้างปัจจุบัน</th>
                        <th data-sort-col="2" data-sort-type="number">สัดส่วนโหลด</th>
                        <th data-sort-col="3" data-sort-type="number">ค้างเก่าสุด</th>
                        <th data-sort-col="4" data-sort-type="number">รับ</th>
                        <th data-sort-col="5" data-sort-type="number">ปิด</th>
                        <th data-sort-col="6" data-sort-type="number">อัตราปิด</th>
                        <th data-sort-col="7" data-sort-type="number">SLA ตรงเวลา</th>
                        <th data-sort-col="8" data-sort-type="number">เวลาตอบรับ (ชม.)</th>
                        <th data-sort-col="9" data-sort-type="number">MTTR (ชม.)</th>
                        <th data-sort-col="10" data-sort-type="number">คะแนน</th>
                        <th data-sort-col="11" data-sort-type="number">ชม.แรงงาน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $tech): ?>
                        <tr>
                            <td><strong><?= e((string) $tech['full_name']) ?></strong></td>
                            <td><span class="badge badge-<?= e((string) $tech['workload_tone']) ?>"><?= e((string) $tech['open_now']) ?></span></td>
                            <td><?= e((string) $tech['workload_share_label']) ?></td>
                            <td><?= e((string) $tech['oldest_open_age_label']) ?></td>
                            <td><?= e((string) $tech['assigned']) ?></td>
                            <td><?= e((string) $tech['resolved']) ?></td>
                            <td><span class="badge badge-<?= e((string) $tech['completion_tone']) ?>"><?= e((string) $tech['completion_label']) ?></span></td>
                            <td><span class="badge badge-<?= e((string) $tech['sla_on_time_tone']) ?>"><?= e((string) $tech['sla_on_time_label']) ?></span></td>
                            <td><?= e((string) $tech['first_response_hours_label']) ?></td>
                            <td><?= e((string) $tech['mttr_hours_label']) ?></td>
                            <td><span class="badge badge-<?= e((string) $tech['avg_rating_tone']) ?>"><?= e((string) $tech['avg_rating_label']) ?></span></td>
                            <td><?= e((string) $tech['labor_hours_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'users',
                'title' => 'ยังไม่มีช่างในระบบ',
                'description' => 'เมื่อมีผู้ใช้บทบาทช่างเทคนิคที่เปิดใช้งาน ระบบจะสรุปภาระงานและผลงานให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
