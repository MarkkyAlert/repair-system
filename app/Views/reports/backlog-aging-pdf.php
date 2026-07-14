<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Backlog Aging PDF</title>
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
        .summary-item { display: inline-block; width: 19%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .summary-item.warning { border-top-color: #f59e0b; }
        .summary-item.danger { border-top-color: #ef4444; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <p class="brand-kicker">BACKLOG AGING</p>
    <h1 class="brand-title">งานค้างตามอายุ</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · แยกตาม<?= e((string) ($dimensionLabel ?? 'ระดับความสำคัญ')) ?> · งานค้าง ณ ปัจจุบัน</p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>แผนก:</strong> <?= e((string) ($filters['department'] ?? 'ทุกแผนก')) ?></td>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวด')) ?></td>
        <td><strong>เก่าสุด:</strong> <?= e((string) ($summary['oldest_label'] ?? '-')) ?></td>
    </tr>
</table>

<div class="stack summary-grid">
    <div class="summary-item">
        <div class="summary-title">งานค้างทั้งหมด</div>
        <div class="summary-value"><?= e((string) ($summary['total'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">ค้าง 0-2 วัน</div>
        <div class="summary-value"><?= e((string) ($summary['bucket_0_3'] ?? 0)) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">ค้าง 3-6 วัน</div>
        <div class="summary-value"><?= e((string) ($summary['bucket_3_7'] ?? 0)) ?></div>
    </div>
    <div class="summary-item warning">
        <div class="summary-title">ค้าง 7-29 วัน</div>
        <div class="summary-value"><?= e((string) ($summary['bucket_7_30'] ?? 0)) ?></div>
    </div>
    <div class="summary-item danger">
        <div class="summary-title">ค้าง ≥30 วัน</div>
        <div class="summary-value"><?= e((string) ($summary['bucket_30_plus'] ?? 0)) ?></div>
    </div>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th><?= e((string) ($dimensionLabel ?? 'ระดับความสำคัญ')) ?></th>
        <th class="num">0-2 วัน</th>
        <th class="num">3-6 วัน</th>
        <th class="num">7-29 วัน</th>
        <th class="num">≥30 วัน</th>
        <th class="num">รวม</th>
        <th class="num">เก่าสุด (วัน)</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['bucket_0_3'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['bucket_3_7'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['bucket_7_30'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['bucket_30_plus'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['total'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['oldest_days'] ?? 0)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
