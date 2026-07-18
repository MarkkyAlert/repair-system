<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Ticket Report PDF</title>
    <style>
        @page { margin: 22px; }
        body { font-family: 'sarabun', sans-serif; font-size: 10px; color: #102a3a; }
        h1, h2, p { margin: 0; }
        .stack { margin-bottom: 14px; }
        .brand-header { padding: 15px 18px; margin-bottom: 14px; background: #0a2233; color: #ffffff; }
        .brand-kicker { color: #5eead4; font-size: 8px; font-weight: bold; letter-spacing: 1px; }
        .brand-title { font-size: 22px; margin-top: 3px; }
        .brand-meta { color: #b9d3d8; margin-top: 4px; }
        .meta-table, .report-table { width: 100%; border-collapse: collapse; }
        .meta-table { background: #f4f8fa; border: 1px solid #d9e5e8; }
        .meta-table td { padding: 6px 8px; vertical-align: top; }
        .report-table th, .report-table td { border-bottom: 1px solid #d9e5e8; padding: 6px; vertical-align: top; text-align: left; }
        .report-table th { background: #0f766e; color: #ffffff; font-size: 8px; font-weight: 700; }
        .report-table tr:nth-child(even) td { background: #f7fafb; }
        .summary-grid { width: 100%; margin-top: 10px; }
        .summary-item { display: inline-block; width: 19%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .section-title { font-size: 12px; font-weight: 700; color: #0a2233; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 2px solid #14b8a6; }
        .section-note { font-size: 8px; color: #607783; margin: -4px 0 6px; }
        .report-table.compact th, .report-table.compact td { padding: 4px 6px; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <?= render_partial('partials/print/pdf-brand') ?>
    <p class="brand-kicker">MAINTENANCE OPERATIONS</p>
    <h1 class="brand-title">รายงานงานซ่อมบำรุง</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?></p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>วันที่เริ่มต้น:</strong> <?= e((string) ($filters['from_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>วันที่สิ้นสุด:</strong> <?= e((string) ($filters['to_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>แผนก:</strong> <?= e((string) ($filters['department'] ?? 'ทุกแผนก')) ?></td>
    </tr>
    <tr>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวด')) ?></td>
        <td><strong>สถานะ:</strong> <?= e((string) ($filters['status'] ?? 'ทุกสถานะ')) ?></td>
        <td></td>
    </tr>
</table>

<div class="stack summary-grid">
    <div class="summary-item">
        <div class="summary-title">รายการ Ticket ทั้งหมด</div>
        <div class="summary-value"><?= e((string) ($summary['total'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">ปิดงาน</div>
        <div class="summary-value"><?= e((string) ($summary['resolved'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เกิน SLA</div>
        <div class="summary-value"><?= e((string) ($summary['overdue'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เวลาแก้ไขเฉลี่ย (ชม.)</div>
        <div class="summary-value"><?= e((string) ($summary['avgResolutionHoursLabel'] ?? '-')) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">คะแนนเฉลี่ย</div>
        <div class="summary-value"><?= e((string) ($summary['avgRatingLabel'] ?? '-')) ?></div>
    </div>
</div>

<?php $analytics = $analytics ?? []; ?>

<?php $sla = $analytics['slaCompliance'] ?? [];
    if (!empty($sla['hasData'])): $slaOverall = $sla['overall'] ?? []; ?>
    <div class="section-title">SLA ตรงตามกำหนด</div>
    <p class="section-note">% งานที่ตอบรับ/แก้ไขได้ทันกำหนด เทียบกับที่เกินกำหนด</p>
    <table class="report-table compact stack">
        <thead>
        <tr>
            <th>ระดับความสำคัญ</th>
            <th class="num">ตอบรับ ตรง</th>
            <th class="num">ตอบรับ เกิน</th>
            <th class="num">ตอบรับ %</th>
            <th class="num">แก้ไข ตรง</th>
            <th class="num">แก้ไข เกิน</th>
            <th class="num">แก้ไข %</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td><strong>รวมทั้งหมด</strong></td>
            <td class="num"><?= e((string) ($slaOverall['response']['met'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($slaOverall['response']['breached'] ?? 0)) ?></td>
            <td class="num"><strong><?= e((string) ($slaOverall['response']['pct_label'] ?? '-')) ?></strong></td>
            <td class="num"><?= e((string) ($slaOverall['resolution']['met'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($slaOverall['resolution']['breached'] ?? 0)) ?></td>
            <td class="num"><strong><?= e((string) ($slaOverall['resolution']['pct_label'] ?? '-')) ?></strong></td>
        </tr>
        <?php foreach (($sla['byPriority'] ?? []) as $p): ?>
            <tr>
                <td><?= e((string) ($p['priority_name'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($p['response']['met'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($p['response']['breached'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($p['response']['pct_label'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($p['resolution']['met'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($p['resolution']['breached'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($p['resolution']['pct_label'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php $techs = $analytics['technicianPerformance'] ?? [];
    if (!empty($techs)): ?>
    <div class="section-title">ผลงานช่างเทคนิค</div>
    <table class="report-table compact stack">
        <thead>
        <tr>
            <th>ช่าง</th>
            <th class="num">ปิดงาน</th>
            <th class="num">ค้าง</th>
            <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
            <th class="num">คะแนนเฉลี่ย</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($techs as $t): ?>
            <tr>
                <td><?= e((string) ($t['full_name'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($t['resolved'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($t['open_now'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($t['mttr_hours_label'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($t['avg_rating_label'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php $labor = $analytics['laborEffort'] ?? [];
    if (!empty($labor['hasData'])): ?>
    <div class="section-title">ชั่วโมงแรงงาน</div>
    <p class="section-note">รวม <?= e((string) ($labor['total_hours_label'] ?? '0')) ?> ชม. · เฉลี่ย <?= e((string) ($labor['avg_hours_label'] ?? '0')) ?> ชม./งาน · <?= e((string) ($labor['labored_tickets'] ?? 0)) ?> งานที่บันทึกแรงงาน</p>
    <table class="report-table compact stack">
        <thead>
        <tr>
            <th>หมวดหมู่งาน</th>
            <th class="num">จำนวนงาน</th>
            <th class="num">งานที่บันทึกแรงงาน</th>
            <th class="num">รวมชั่วโมง</th>
            <th class="num">เฉลี่ย/งาน (ชม.)</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (($labor['byCategory'] ?? []) as $c): ?>
            <tr>
                <td><?= e((string) ($c['category_name'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($c['tickets'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($c['labored_tickets'] ?? 0)) ?></td>
                <td class="num"><?= e((string) ($c['labor_hours_label'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($c['avg_hours_label'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php $assets = $analytics['assetReliability'] ?? [];
    if (!empty($assets)): ?>
    <div class="section-title">ทรัพย์สินเสียบ่อย</div>
    <table class="report-table compact stack">
        <thead>
        <tr>
            <th>รหัส</th>
            <th>ชื่อ</th>
            <th>หมวดหมู่</th>
            <th>สถานที่</th>
            <th>สถานะ</th>
            <th class="num">จำนวนครั้ง</th>
            <th>ครั้งล่าสุด</th>
            <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
            <th class="num">ชม.แรงงาน</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
            <tr>
                <td><?= e((string) ($a['asset_code'] ?? '-')) ?></td>
                <td><?= e((string) ($a['name'] ?? '-')) ?></td>
                <td><?= e((string) ($a['category_name'] ?? '-')) ?></td>
                <td><?= e((string) ($a['location_name'] ?? '-')) ?></td>
                <td><?= e((string) ($a['status_label'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($a['failure_count'] ?? 0)) ?></td>
                <td><?= e((string) ($a['last_failure'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($a['avg_resolution_hours_label'] ?? '-')) ?></td>
                <td class="num"><?= e((string) ($a['labor_hours_label'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<div class="section-title">รายการ Ticket</div>
<table class="report-table">
    <thead>
    <tr>
        <th>เลขที่</th>
        <th>ผู้แจ้ง</th>
        <th>แผนก</th>
        <th>หมวดหมู่</th>
        <th>ช่างเทคนิค</th>
        <th>สถานะ</th>
        <th>วันที่แจ้ง</th>
        <th>วันที่แก้ไข</th>
        <th>เวลาแก้ไข (ชม.)</th>
        <th>เกิน SLA</th>
        <th>สถานะ SLA</th>
        <th>คะแนน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['ticket_no'] ?? '-')) ?><br><?= e((string) ($row['title'] ?? '-')) ?></td>
            <td><?= e((string) ($row['requester_name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['department_name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['category_name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['technician_name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['status_label'] ?? '-')) ?></td>
            <td><?= e((string) ($row['requested_at'] ?? '-')) ?></td>
            <td><?= e((string) ($row['resolved_at'] ?? '-')) ?></td>
            <td><?= e((string) ($row['resolution_hours_label'] ?? '-')) ?></td>
            <td><?= e((string) ($row['sla_overdue_label'] ?? 'No')) ?></td>
            <td><?= e((string) ($row['sla_label'] ?? '-')) ?></td>
            <td><?= e((string) ($row['rating_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
