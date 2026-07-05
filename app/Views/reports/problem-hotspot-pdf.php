<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Problem Hotspot PDF</title>
    <style>
        @page { margin: 22px; }
        body { font-family: 'sarabun', sans-serif; font-size: 10px; color: #102a3a; }
        h1, h2, p { margin: 0; }
        .stack { margin-bottom: 14px; }
        .brand-header { padding: 15px 18px; margin-bottom: 14px; background: #0a2233; color: #ffffff; }
        .brand-kicker { color: #fca5a5; font-size: 8px; font-weight: bold; letter-spacing: 1px; }
        .brand-title { font-size: 22px; margin-top: 3px; }
        .brand-meta { color: #b9d3d8; margin-top: 4px; }
        .meta-table, .report-table { width: 100%; border-collapse: collapse; }
        .meta-table { background: #f4f8fa; border: 1px solid #d9e5e8; }
        .meta-table td { padding: 6px 8px; vertical-align: top; }
        .report-table th, .report-table td { border-bottom: 1px solid #d9e5e8; padding: 5px 6px; vertical-align: top; text-align: left; }
        .report-table th { background: #0f766e; color: #ffffff; font-size: 8px; font-weight: 700; }
        .report-table tr:nth-child(even) td { background: #f7fafb; }
        .summary-grid { width: 100%; margin-top: 10px; }
        .summary-item { display: inline-block; width: 24%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #ef4444; box-sizing: border-box; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .num { text-align: right; }
        .health-danger { color: #b91c1c; font-weight: 700; }
        .health-warning { color: #b45309; font-weight: 700; }
        .health-success { color: #0f766e; font-weight: 700; }
        .reason { font-size: 8px; color: #607783; }
    </style>
</head>
<body>
<div class="brand-header">
    <p class="brand-kicker">PROBLEM HOTSPOT</p>
    <h1 class="brand-title">พื้นที่ปัญหา (แผนก / สถานที่)</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · แยกตาม<?= e((string) ($dimensionLabel ?? 'แผนก')) ?></p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>วันที่เริ่มต้น:</strong> <?= e((string) ($filters['from_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>วันที่สิ้นสุด:</strong> <?= e((string) ($filters['to_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>แผนก:</strong> <?= e((string) ($filters['department'] ?? 'ทุกแผนก')) ?></td>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวด')) ?></td>
    </tr>
</table>

<div class="stack summary-grid">
    <div class="summary-item">
        <div class="summary-title">พื้นที่ปัญหา</div>
        <div class="summary-value"><?= e((string) ($summary['problem_areas'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">แจ้งซ่อมรวม</div>
        <div class="summary-value"><?= e((string) ($summary['total_tickets'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เกิน SLA รวม</div>
        <div class="summary-value"><?= e((string) ($summary['total_overdue'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">ชม.แรงงานรวม</div>
        <div class="summary-value"><?= e((string) ($summary['labor_hours_label'] ?? '0')) ?></div>
    </div>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th><?= e((string) ($dimensionLabel ?? 'แผนก')) ?></th>
        <th>คะแนนพื้นที่</th>
        <th class="num">แจ้งซ่อม</th>
        <th class="num">งานค้าง</th>
        <th class="num">เกิน SLA</th>
        <th class="num">%เกิน SLA</th>
        <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
        <th class="num">ชม.แรงงาน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['label'] ?? '-')) ?></td>
            <td>
                <span class="health-<?= e((string) ($row['hotspot_tone'] ?? 'success')) ?>"><?= e((string) ($row['hotspot_label'] ?? '-')) ?></span>
                <div class="reason"><?= e((string) ($row['hotspot_reason'] ?? '')) ?></div>
            </td>
            <td class="num"><?= e((string) ($row['ticket_count'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['open_count'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['overdue_count'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['overdue_rate_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['avg_resolution_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['labor_hours_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
