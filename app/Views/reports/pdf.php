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
    </style>
</head>
<body>
<div class="brand-header">
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
        <div class="summary-title">แก้ไข/เสร็จสิ้น</div>
        <div class="summary-value"><?= e((string) ($summary['resolved'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เกิน SLA</div>
        <div class="summary-value"><?= e((string) ($summary['overdue'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เวลาแก้ไขเฉลี่ย (ชม.)</div>
        <div class="summary-value"><?= e((string) ($summary['avgResolutionHours'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">คะแนนเฉลี่ย</div>
        <div class="summary-value"><?= e((string) ($summary['avgRatingLabel'] ?? '-')) ?></div>
    </div>
</div>

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
