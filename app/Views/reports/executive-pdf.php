<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Executive Summary PDF</title>
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
        .kpi-grid { width: 100%; margin-top: 10px; }
        .kpi-item { display: inline-block; width: 32%; margin-right: .6%; margin-bottom: 6px; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .kpi-item.danger { border-top-color: #ef4444; }
        .kpi-item.warning { border-top-color: #f59e0b; }
        .kpi-item.success { border-top-color: #10b981; }
        .kpi-title { font-size: 8px; color: #607783; }
        .kpi-value { font-size: 18px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .kpi-delta { font-size: 8px; color: #607783; margin-top: 2px; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <?= render_partial('partials/print/pdf-brand') ?>
    <p class="brand-kicker">EXECUTIVE SUMMARY</p>
    <h1 class="brand-title">สรุปผู้บริหาร (เทียบงวด)</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · งวดนี้ <?= e((string) ($period['this'] ?? '-')) ?> · เทียบงวดก่อน <?= e((string) ($period['prev'] ?? '-')) ?></p>
</div>

<table class="meta-table stack">
    <tr>
        <td><strong>แผนก:</strong> <?= e((string) ($filters['department'] ?? 'ทุกแผนก')) ?></td>
        <td><strong>หมวดหมู่:</strong> <?= e((string) ($filters['category'] ?? 'ทุกหมวด')) ?></td>
    </tr>
</table>

<div class="stack kpi-grid">
    <?php foreach (($kpis ?? []) as $kpi): ?>
        <div class="kpi-item <?= e((string) ($kpi['tone'] ?? 'default')) ?>">
            <div class="kpi-title"><?= e((string) ($kpi['label'] ?? '-')) ?></div>
            <div class="kpi-value"><?= e((string) ($kpi['value_label'] ?? '-')) ?></div>
            <div class="kpi-delta"><?= e((string) ($kpi['delta_label'] ?? '—')) ?> (<?= e((string) ($kpi['pct_label'] ?? '—')) ?>) · งวดก่อน <?= e((string) ($kpi['prev_value_label'] ?? '-')) ?><?php if (!empty($kpi['sample_label'])): ?> · <?= e((string) $kpi['sample_label']) ?><?php endif; ?></div>
        </div>
    <?php endforeach; ?>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th>KPI</th>
        <th class="num">งวดนี้</th>
        <th class="num">งวดก่อน</th>
        <th class="num">เปลี่ยนแปลง</th>
        <th class="num">%เปลี่ยน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($kpis ?? []) as $kpi): ?>
        <tr>
            <td><?= e((string) ($kpi['label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($kpi['value_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($kpi['prev_value_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($kpi['delta_label'] ?? '—')) ?></td>
            <td class="num"><?= e((string) ($kpi['pct_label'] ?? '—')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
