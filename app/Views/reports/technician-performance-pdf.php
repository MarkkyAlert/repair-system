<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Technician Performance PDF</title>
    <style>
        @page { margin: 20px; }
        body { font-family: 'sarabun', sans-serif; font-size: 9px; color: #102a3a; }
        h1, h2, p { margin: 0; }
        .stack { margin-bottom: 14px; }
        .brand-header { padding: 15px 18px; margin-bottom: 14px; background: #0a2233; color: #ffffff; }
        .brand-kicker { color: #5eead4; font-size: 8px; font-weight: bold; letter-spacing: 1px; }
        .brand-title { font-size: 22px; margin-top: 3px; }
        .brand-meta { color: #b9d3d8; margin-top: 4px; }
        .meta-table, .report-table { width: 100%; border-collapse: collapse; }
        .meta-table { background: #f4f8fa; border: 1px solid #d9e5e8; font-size: 10px; }
        .meta-table td { padding: 6px 8px; vertical-align: top; }
        .report-table th, .report-table td { border-bottom: 1px solid #d9e5e8; padding: 4px 5px; vertical-align: top; text-align: left; }
        .report-table th { background: #0f766e; color: #ffffff; font-size: 8px; font-weight: 700; }
        .report-table tr:nth-child(even) td { background: #f7fafb; }
        .summary-grid { width: 100%; margin-top: 10px; }
        .summary-item { display: inline-block; width: 24%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <p class="brand-kicker">TECHNICIAN WORKLOAD &amp; PERFORMANCE</p>
    <h1 class="brand-title">ผลงานและภาระงานทีมช่าง</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · งานค้าง/ค้างเก่าสุด/สัดส่วนโหลด = ณ ตอนนี้</p>
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
        <div class="summary-title">ช่างทั้งหมด</div>
        <div class="summary-value"><?= e((string) ($summary['technicians'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">งานค้างรวม (ตอนนี้)</div>
        <div class="summary-value"><?= e((string) ($summary['open_now'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">ปิดในช่วงนี้</div>
        <div class="summary-value"><?= e((string) ($summary['resolved'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">SLA ตรงเวลา (ทีม)</div>
        <div class="summary-value"><?= e((string) ($summary['sla_on_time_label'] ?? '-')) ?></div>
    </div>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th>ช่าง</th>
        <th class="num">งานค้าง</th>
        <th class="num">สัดส่วนโหลด</th>
        <th class="num">ค้างเก่าสุด</th>
        <th class="num">รับ</th>
        <th class="num">ปิดงาน</th>
        <th class="num">อัตราปิดงาน</th>
        <th class="num">SLA ตรงเวลา</th>
        <th class="num">ตอบรับ (ชม.)</th>
        <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
        <th class="num">คะแนน</th>
        <th class="num">ชม.แรงงาน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['full_name'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['open_now'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['workload_share_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['oldest_open_age_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['assigned'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['resolved'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['completion_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['sla_on_time_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['first_response_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['mttr_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['avg_rating_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['labor_hours_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
