<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>SLA Breach Analysis PDF</title>
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
    </style>
</head>
<body>
<div class="brand-header">
    <?= render_partial('partials/print/pdf-brand') ?>
    <p class="brand-kicker">SLA BREACH ANALYSIS</p>
    <h1 class="brand-title">วิเคราะห์ SLA เกินกำหนด</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · แยกตาม<?= e((string) ($dimensionLabel ?? 'ระดับความสำคัญ')) ?></p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>วันที่เริ่มต้น:</strong> <?= e((string) ($filters['from_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>วันที่สิ้นสุด:</strong> <?= e((string) ($filters['to_date'] ?? 'ไม่ระบุ')) ?></td>
        <td><strong>ระดับความสำคัญ:</strong> <?= e((string) ($filters['priority'] ?? 'ทุกระดับ')) ?></td>
    </tr>
    <tr>
        <td><strong>แผนก:</strong> <?= e((string) ($filters['department'] ?? 'ทุกแผนก')) ?></td>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวดหมู่')) ?></td>
        <td><strong>สถานที่:</strong> <?= e((string) ($filters['location'] ?? 'ทุกสถานที่')) ?></td>
    </tr>
</table>

<div class="stack summary-grid">
    <div class="summary-item">
        <div class="summary-title">เกินกำหนดทั้งหมด</div>
        <div class="summary-value"><?= e((string) ($summary['total_breached'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เกินจากการตอบรับ</div>
        <div class="summary-value"><?= e((string) ($summary['response_breached'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เกินจากการแก้ไข</div>
        <div class="summary-value"><?= e((string) ($summary['resolution_breached'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">%เกินโดยรวม</div>
        <div class="summary-value"><?= e((string) ($summary['breach_rate_label'] ?? '-')) ?></div>
    </div>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th><?= e((string) ($dimensionLabel ?? 'ระดับความสำคัญ')) ?></th>
        <th class="num">ตอบรับ เกิน</th>
        <th class="num">แก้ไข เกิน</th>
        <th class="num">เกินรวม</th>
        <th class="num">ทันกำหนด</th>
        <th class="num">%เกิน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['response']['breached'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['resolution']['breached'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['total_breached'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['total_met'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['breach_rate_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
