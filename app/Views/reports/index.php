<?php
$filterState = $filters['selected'] ?? [];
$querySuffix = !empty($filters['query_string']) ? '?' . (string) $filters['query_string'] : '';
?>
<section class="stack-lg">
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
            'title' => 'Tickets ทั้งหมด',
            'value' => (string) ($summary['total'] ?? 0),
            'meta' => 'รายการในรายงานปัจจุบัน',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'แก้ไข/เสร็จสิ้น',
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
            'value' => (string) ($summary['avgResolutionHours'] ?? 0),
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

    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>ส่งออกรายงาน</strong>
            <span class="helper-text"><?= e((string) count($rows ?? [])) ?> รายการ</span>
        </div>
        <div class="action-bar-right">
            <details class="export-dropdown">
                <summary class="btn btn-primary btn-md">
                    <?= lucide('download', 'button-icon') ?>
                    <span>ส่งออกรายงาน</span>
                    <?= lucide('chevron-down', 'button-icon') ?>
                </summary>
                <div class="export-dropdown-menu">
                    <a href="<?= e(url('/reports/export/excel' . $querySuffix)) ?>" class="export-dropdown-item">
                        <?= lucide('clipboard-list', 'button-icon') ?>
                        <span>Export Excel</span>
                    </a>
                    <a href="<?= e(url('/reports/export/pdf' . $querySuffix)) ?>" class="export-dropdown-item">
                        <?= lucide('file-text', 'button-icon') ?>
                        <span>Export PDF</span>
                    </a>
                    <a href="<?= e(url('/reports/export/csv' . $querySuffix)) ?>" class="export-dropdown-item">
                        <?= lucide('download', 'button-icon') ?>
                        <span>Export CSV</span>
                    </a>
                </div>
            </details>
        </div>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">รายการ Ticket พร้อมสถานะ SLA</h2>
            <span class="badge badge-default"><?= e((string) count($rows ?? [])) ?> รายการ</span>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">รายการ Ticket พร้อมสถานะ SLA จำนวน <?= e((string) count($rows ?? [])) ?> รายการ</caption>
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
                        <th data-sort-col="9">เกิน SLA</th>
                        <th data-sort-col="10">สถานะ SLA</th>
                        <th data-sort-col="11" data-sort-type="number">คะแนน</th>
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
                            <td><?= e($row['sla_overdue_label']) ?></td>
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
                'description' => 'ลองล้างตัวกรองหรือเปลี่ยนช่วงเวลาเพื่อดูข้อมูลและ export report',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
