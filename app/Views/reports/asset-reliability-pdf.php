<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Asset Reliability Report PDF</title>
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
        .report-table th, .report-table td { border-bottom: 1px solid #d9e5e8; padding: 5px 6px; vertical-align: top; text-align: left; }
        .report-table th { background: #0f766e; color: #ffffff; font-size: 8px; font-weight: 700; }
        .report-table tr:nth-child(even) td { background: #f7fafb; }
        .summary-grid { width: 100%; margin-top: 10px; border-collapse: separate; border-spacing: 5px 0; table-layout: fixed; }
        .summary-item { padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; vertical-align: top; }
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
    <p class="brand-kicker">ASSET MAINTENANCE</p>
    <h1 class="brand-title">รายงานสุขภาพทรัพย์สิน</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?></p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>วันที่เริ่มต้น:</strong> <?= e((string) ($filters['from_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>วันที่สิ้นสุด:</strong> <?= e((string) ($filters['to_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวดหมู่')) ?></td>
    </tr>
    <tr>
        <td><strong>สถานที่:</strong> <?= e((string) ($filters['location'] ?? 'ทุกสถานที่')) ?></td>
        <td><strong>สถานะ:</strong> <?= e((string) ($filters['status'] ?? 'ทุกสถานะ')) ?></td>
        <td></td>
    </tr>
</table>

<table class="stack summary-grid">
    <tr>
        <td class="summary-item">
            <div class="summary-title">ทรัพย์สินที่แจ้งซ่อม</div>
            <div class="summary-value"><?= e((string) ($summary['assets'] ?? 0)) ?></div>
        </td>
        <td class="summary-item">
            <div class="summary-title">ควรเปลี่ยน</div>
            <div class="summary-value"><?= e((string) ($summary['replace'] ?? 0)) ?></div>
        </td>
        <td class="summary-item">
            <div class="summary-title">เฝ้าระวัง</div>
            <div class="summary-value"><?= e((string) ($summary['watch'] ?? 0)) ?></div>
        </td>
        <td class="summary-item">
            <div class="summary-title">Downtime รวม (ชม.)</div>
            <div class="summary-value"><?= e((string) ($summary['downtimeHoursLabel'] ?? '0')) ?></div>
        </td>
        <td class="summary-item">
            <div class="summary-title">ชม.แรงงานรวม</div>
            <div class="summary-value"><?= e((string) ($summary['laborHoursLabel'] ?? '0')) ?></div>
        </td>
    </tr>
</table>

<table class="report-table">
    <thead>
    <tr>
        <th>รหัส</th>
        <th>หมวดหมู่</th>
        <th>สถานที่</th>
        <th>สุขภาพ</th>
        <th class="num">จำนวนครั้ง</th>
        <th>ครั้งล่าสุด</th>
        <th class="num">MTBF (วัน)</th>
        <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
        <th class="num">Downtime (ชม.)</th>
        <th class="num">ชม.แรงงาน</th>
        <th class="num">อายุ (ปี)</th>
        <th>ประกัน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['asset_code'] ?? '-')) ?><br><?= e((string) ($row['name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['category_name'] ?? '-')) ?></td>
            <td><?= e((string) ($row['location_name'] ?? '-')) ?></td>
            <td>
                <span class="health-<?= e((string) ($row['health_tone'] ?? 'success')) ?>"><?= e((string) ($row['health_label'] ?? '-')) ?></span>
                <div class="reason"><?= e((string) ($row['health_reason'] ?? '')) ?></div>
            </td>
            <td class="num"><?= e((string) ($row['failure_count'] ?? 0)) ?></td>
            <td><?= e((string) ($row['last_failure'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['mtbf_days_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['avg_resolution_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['downtime_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['labor_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['age_label'] ?? '-')) ?></td>
            <td><?= e((string) ($row['warranty_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
