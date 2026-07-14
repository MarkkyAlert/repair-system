<?php
$filterState = $filters['selected'] ?? [];
$rowCount = count($rows ?? []);
$rowsMeta = $rowsMeta ?? ['displayed' => $rowCount, 'total' => $rowCount, 'limit' => 250, 'capped' => false];
$isCapped = !empty($rowsMeta['capped']);
?>
<section class="stack-lg">
    <h1 class="sr-only">รายงานและวิเคราะห์งานซ่อม</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ข้อมูลเพื่อการตัดสินใจ',
        'title' => 'รายงานและวิเคราะห์งานซ่อม',
        'description' => 'วิเคราะห์ปริมาณงาน SLA และส่งออกรายงาน',
    ]) ?>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">กำหนดช่วงข้อมูลรายงาน</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports')) ?>" class="stack-md">
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
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรองข้อมูล', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'x']) ?>
                <span class="helper-text">ตัวกรองที่ใช้งาน: <?= e((string) ($filters['active_count'] ?? 0)) ?></span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-5 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'รายการ Ticket ทั้งหมด',
            'value' => (string) ($summary['total'] ?? 0),
            'meta' => 'รายการในรายงานปัจจุบัน',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ปิดงาน',
            'value' => (string) ($summary['resolved'] ?? 0),
            'meta' => 'ปริมาณงานที่ปิดแล้ว',
            'tone' => 'success',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกิน SLA',
            'value' => (string) ($summary['overdue'] ?? 0),
            'meta' => 'Ticket ที่เกินกำหนดปัจจุบัน',
            'tone' => 'danger',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เวลาแก้ไขเฉลี่ย (ชม.)',
            'value' => (string) ($summary['avgResolutionHoursLabel'] ?? '-'),
            'meta' => 'ตั้งแต่แจ้งจนแก้ไขสำเร็จ',
            'tone' => 'info',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'คะแนนเฉลี่ย',
            'value' => (string) ($summary['avgRatingLabel'] ?? '-'),
            'meta' => 'ความพึงพอใจของผู้แจ้ง',
            'tone' => 'warning',
        ]) ?>
    </div>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>รายงานตามตัวกรองปัจจุบัน</strong>
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
                    // Export ใช้ POST + CSRF (กัน CSRF-via-GET ที่หน้าภายนอกฝัง <img src> auto-trigger export job ได้)
                    // hidden inputs พา filter ปัจจุบัน (status/department_id/category_id/from_date/to_date) ไปด้วย
                    $exportHidden = '';
        foreach (['status', 'department_id', 'category_id', 'from_date', 'to_date'] as $exportField) {
            $exportValue = (string) ($filterState[$exportField] ?? '');
            if ($exportValue !== '') {
                $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
            }
        }
        ?>
                    <form method="post" action="<?= e(url('/reports/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/export/csv')) ?>" class="export-dropdown-form">
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
                <h2 class="panel-title">SLA ตรงตามกำหนด</h2>
                <p class="field-hint">อัตราทำงานทันกำหนด SLA — การตอบรับ และ การแก้ไข แยกตามระดับความสำคัญ</p>
            </div>
        </div>

        <?php $slaOverall = $slaCompliance['overall'] ?? []; ?>
        <div class="stat-grid stat-grid-2">
            <?= render_partial('partials/components/card', [
                'title' => 'SLA การตอบรับ',
                'value' => (string) ($slaOverall['response']['pct_label'] ?? '-'),
                'meta' => 'ตรงกำหนด ' . (int) ($slaOverall['response']['met'] ?? 0) . ' · เกิน ' . (int) ($slaOverall['response']['breached'] ?? 0),
                'tone' => (string) ($slaOverall['response']['tone'] ?? 'default'),
            ]) ?>
            <?= render_partial('partials/components/card', [
                'title' => 'SLA การแก้ไข',
                'value' => (string) ($slaOverall['resolution']['pct_label'] ?? '-'),
                'meta' => 'ตรงกำหนด ' . (int) ($slaOverall['resolution']['met'] ?? 0) . ' · เกิน ' . (int) ($slaOverall['resolution']['breached'] ?? 0),
                'tone' => (string) ($slaOverall['resolution']['tone'] ?? 'default'),
            ]) ?>
        </div>

        <?php if (!empty($slaCompliance['byPriority'])): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">SLA ตรงตามกำหนด แยกตามระดับความสำคัญ</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">ระดับความสำคัญ</th>
                        <th data-sort-col="1" data-sort-type="number">ตอบรับ ตรง</th>
                        <th data-sort-col="2" data-sort-type="number">ตอบรับ เกิน</th>
                        <th data-sort-col="3" data-sort-type="number">ตอบรับ %</th>
                        <th data-sort-col="4" data-sort-type="number">แก้ไข ตรง</th>
                        <th data-sort-col="5" data-sort-type="number">แก้ไข เกิน</th>
                        <th data-sort-col="6" data-sort-type="number">แก้ไข %</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($slaCompliance['byPriority'] as $sla): ?>
                        <tr>
                            <td><?= e($sla['priority_name']) ?></td>
                            <td><?= e((string) $sla['response']['met']) ?></td>
                            <td><?= e((string) $sla['response']['breached']) ?></td>
                            <td><span class="badge badge-<?= e($sla['response']['tone']) ?>"><?= e($sla['response']['pct_label']) ?></span></td>
                            <td><?= e((string) $sla['resolution']['met']) ?></td>
                            <td><?= e((string) $sla['resolution']['breached']) ?></td>
                            <td><span class="badge badge-<?= e($sla['resolution']['tone']) ?>"><?= e($sla['resolution']['pct_label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clock',
                'title' => 'ยังไม่มีข้อมูล SLA ในช่วงที่กรอง',
                'description' => 'เมื่อมี ticket ที่มีการวัด SLA ในช่วงเวลาที่เลือก ระบบจะสรุป %ตรงตามกำหนดให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ผลงานช่างเทคนิค</h2>
                <p class="field-hint">สรุปผลงานช่างในช่วงที่กรอง — ปริมาณงาน · เวลาซ่อมเฉลี่ย (MTTR) · คะแนน · ชั่วโมงแรงงาน</p>
            </div>
            <?php if (!empty($technicianPerformance)): ?>
                <span class="badge badge-default"><?= e((string) count($technicianPerformance)) ?> คน</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($technicianPerformance)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ผลงานช่างเทคนิคต่อคน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">ช่าง</th>
                        <th data-sort-col="1" data-sort-type="number">มอบหมาย</th>
                        <th data-sort-col="2" data-sort-type="number">ปิดงาน</th>
                        <th data-sort-col="3" data-sort-type="number">ค้าง</th>
                        <th data-sort-col="4" data-sort-type="number">เวลาซ่อมเฉลี่ย (ชม.)</th>
                        <th data-sort-col="5" data-sort-type="number">คะแนนเฉลี่ย</th>
                        <th data-sort-col="6" data-sort-type="number">ชม.แรงงาน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($technicianPerformance as $tech): ?>
                        <tr>
                            <td><?= e($tech['full_name']) ?></td>
                            <td><?= e((string) $tech['assigned']) ?></td>
                            <td><?= e((string) $tech['resolved']) ?></td>
                            <td><?= e((string) $tech['open']) ?></td>
                            <td><?= e($tech['mttr_hours_label']) ?></td>
                            <td><span class="badge badge-<?= e($tech['avg_rating_tone']) ?>"><?= e($tech['avg_rating_label']) ?></span></td>
                            <td><?= e($tech['labor_hours_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'wrench',
                'title' => 'ยังไม่มีผลงานช่างในช่วงที่กรอง',
                'description' => 'เมื่อมี ticket ที่มอบหมายให้ช่างในช่วงเวลาที่เลือก ระบบจะสรุปผลงานให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ชั่วโมงแรงงาน</h2>
                <p class="field-hint">สรุปชั่วโมงแรงงานที่ช่างบันทึก (จาก work order) แยกตามหมวดงาน — ดูว่างานประเภทไหนใช้แรงงานมากสุด</p>
            </div>
        </div>

        <div class="stat-grid stat-grid-3">
            <?= render_partial('partials/components/card', [
                'title' => 'รวมชั่วโมงแรงงาน',
                'value' => (string) ($laborEffort['total_hours_label'] ?? '-'),
                'meta' => 'ชั่วโมงในช่วงที่กรอง',
                'tone' => 'info',
            ]) ?>
            <?= render_partial('partials/components/card', [
                'title' => 'เฉลี่ยต่องาน',
                'value' => (string) ($laborEffort['avg_hours_label'] ?? '-'),
                'meta' => 'ชั่วโมง/งานที่บันทึกแรงงาน',
                'tone' => 'default',
            ]) ?>
            <?= render_partial('partials/components/card', [
                'title' => 'จำนวนงานที่บันทึกแรงงาน',
                'value' => (string) ($laborEffort['labored_tickets'] ?? 0),
                'meta' => 'งานที่มีการบันทึกเวลาซ่อม',
                'tone' => 'default',
            ]) ?>
        </div>

        <?php if (!empty($laborEffort['byCategory'])): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ชั่วโมงแรงงานแยกตามหมวดงาน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">หมวดหมู่งาน</th>
                        <th data-sort-col="1" data-sort-type="number">จำนวนงาน</th>
                        <th data-sort-col="2" data-sort-type="number">งานที่บันทึกแรงงาน</th>
                        <th data-sort-col="3" data-sort-type="number">รวมชั่วโมง</th>
                        <th data-sort-col="4" data-sort-type="number">เฉลี่ย/งาน (ชม.)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($laborEffort['byCategory'] as $labor): ?>
                        <tr>
                            <td><?= e($labor['category_name']) ?></td>
                            <td><?= e((string) $labor['tickets']) ?></td>
                            <td><?= e((string) $labor['labored_tickets']) ?></td>
                            <td><strong><?= e($labor['labor_hours_label']) ?></strong></td>
                            <td><?= e($labor['avg_hours_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clock',
                'title' => 'ยังไม่มีข้อมูลชั่วโมงแรงงานในช่วงที่กรอง',
                'description' => 'เมื่อช่างบันทึกเวลาซ่อม (labor) ในงานที่ปิด ระบบจะสรุปชั่วโมงแรงงานให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ทรัพย์สินที่แจ้งซ่อมบ่อย</h2>
                <p class="field-hint">จัดอันดับทรัพย์สินตามจำนวนครั้งที่แจ้งซ่อมในช่วงที่กรอง — ช่วยตัดสินใจว่าควรซ่อมใหญ่หรือเปลี่ยนอุปกรณ์ตัวไหน</p>
            </div>
            <?php if (!empty($assetReliability)): ?>
                <span class="badge badge-default"><?= e((string) count($assetReliability)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($assetReliability)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ทรัพย์สินที่แจ้งซ่อมบ่อย จัดอันดับตามจำนวนครั้งที่แจ้งซ่อม</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">อันดับ</th>
                        <th data-sort-col="1">ทรัพย์สิน</th>
                        <th data-sort-col="2">หมวดหมู่</th>
                        <th data-sort-col="3">สถานที่</th>
                        <th data-sort-col="4">สถานะ</th>
                        <th data-sort-col="5" data-sort-type="number">จำนวนครั้งที่แจ้งซ่อม</th>
                        <th data-sort-col="6" data-sort-type="date">ครั้งล่าสุด</th>
                        <th data-sort-col="7" data-sort-type="number">เวลาซ่อมเฉลี่ย (ชม.)</th>
                        <th data-sort-col="8" data-sort-type="number">ชม.แรงงาน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($assetReliability as $index => $asset): ?>
                        <tr>
                            <td class="leaderboard-number"><?= e((string) ($index + 1)) ?></td>
                            <td>
                                <a href="<?= e(url($asset['detail_url'])) ?>" class="leaderboard-number"><?= e($asset['asset_code']) ?></a>
                                <div class="helper-text"><?= e($asset['name']) ?></div>
                            </td>
                            <td><?= e($asset['category_name']) ?></td>
                            <td><?= e($asset['location_name']) ?></td>
                            <td><span class="badge badge-<?= e($asset['status_tone']) ?>"><?= e($asset['status_label']) ?></span></td>
                            <td><strong><?= e((string) $asset['failure_count']) ?></strong> ครั้ง</td>
                            <td><?= e($asset['last_failure']) ?></td>
                            <td><?= e($asset['avg_resolution_hours_label']) ?></td>
                            <td><?= e($asset['labor_hours_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'wrench',
                'title' => 'ยังไม่มีทรัพย์สินที่แจ้งซ่อมในช่วงนี้',
                'description' => 'เมื่อมี ticket ที่ผูกกับทรัพย์สินในช่วงเวลาที่เลือก ระบบจะจัดอันดับให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">รายการ Ticket พร้อมสถานะ SLA</h2>
            <?php if ($isCapped): ?>
                <span class="badge badge-warning">แสดง <?= e((string) $rowsMeta['displayed']) ?> จาก <?= e((string) $rowsMeta['total']) ?> รายการ</span>
            <?php else: ?>
                <span class="badge badge-default"><?= e((string) $rowsMeta['total']) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if ($isCapped): ?>
            <div class="table-cap-notice" role="status">
                <?= lucide('info', 'h-4 w-4') ?>
                <span>แสดงเฉพาะ <strong><?= e((string) $rowsMeta['displayed']) ?></strong> รายการล่าสุด จากทั้งหมด <strong><?= e((string) $rowsMeta['total']) ?></strong> รายการ — กรองข้อมูลให้แคบลง หรือ<strong>ส่งออกไฟล์</strong>เพื่อดูข้อมูลครบทุกรายการ</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">รายการ Ticket พร้อมสถานะ SLA<?= $isCapped ? ' แสดง ' . e((string) $rowsMeta['displayed']) . ' จาก ' . e((string) $rowsMeta['total']) . ' รายการ' : ' จำนวน ' . e((string) $rowsMeta['total']) . ' รายการ' ?></caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0">เลขที่</th>
                        <th data-sort-col="1">ผู้แจ้ง</th>
                        <th data-sort-col="2">แผนก</th>
                        <th data-sort-col="3">หมวดหมู่</th>
                        <th data-sort-col="4">ช่างเทคนิค</th>
                        <th data-sort-col="5">สถานะ</th>
                        <th data-sort-col="6" data-sort-type="date">วันที่แจ้ง</th>
                        <th data-sort-col="7" data-sort-type="date">วันที่แก้ไข</th>
                        <th data-sort-col="8" data-sort-type="number">เวลาแก้ไข (ชม.)</th>
                        <th data-sort-col="9">สถานะ SLA</th>
                        <th data-sort-col="10" data-sort-type="number">คะแนน</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= e(url($row['detail_url'])) ?>" class="leaderboard-number"><?= e($row['ticket_no']) ?></a>
                                <div class="helper-text"><?= e($row['title']) ?></div>
                            </td>
                            <td><?= e($row['requester_name']) ?></td>
                            <td><?= e($row['department_name']) ?></td>
                            <td><?= e($row['category_name']) ?></td>
                            <td><?= e($row['technician_name']) ?></td>
                            <td><?= e($row['status_label']) ?></td>
                            <td><?= e($row['requested_at']) ?></td>
                            <td><?= e($row['resolved_at']) ?></td>
                            <td><?= e($row['resolution_hours_label']) ?></td>
                            <td><?= e($row['sla_label']) ?></td>
                            <td><?= e($row['rating_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'file-text',
                'title' => 'ไม่พบข้อมูลรายงานตามตัวกรองนี้',
                'description' => 'ลองล้างตัวกรองหรือเปลี่ยนช่วงเวลาเพื่อดูข้อมูลและส่งออกรายงาน',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
