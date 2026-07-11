<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Ticket Trend PDF</title>
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
        .summary-grid { width: 100%; margin-top: 10px; }
        .summary-item { display: inline-block; width: 24%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .summary-delta { font-size: 8px; color: #607783; margin-top: 2px; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <p class="brand-kicker">TICKET TREND</p>
    <h1 class="brand-title">แนวโน้มงานซ่อมตามเวลา</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · ความละเอียด<?= e((string) ($granularityLabel ?? 'รายเดือน')) ?> · งวดล่าสุด <?= e((string) ($summary['latest_label'] ?? '-')) ?></p>
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
        <div class="summary-title">แจ้งซ่อม (งวดล่าสุด)</div>
        <div class="summary-value"><?= e((string) ($summary['created']['value'] ?? 0)) ?></div>
        <div class="summary-delta">เทียบงวดก่อน <?= e((string) ($summary['created']['delta_label'] ?? '—')) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">SLA ตรงเวลา (งวดล่าสุด)</div>
        <div class="summary-value"><?= e((string) ($summary['sla']['value'] ?? '-')) ?></div>
        <div class="summary-delta">เทียบงวดก่อน <?= e((string) ($summary['sla']['delta_label'] ?? '—')) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">เวลาซ่อมเฉลี่ย (งวดล่าสุด)</div>
        <div class="summary-value"><?= e((string) ($summary['mttr']['value'] ?? '-')) ?></div>
        <div class="summary-delta">เทียบงวดก่อน <?= e((string) ($summary['mttr']['delta_label'] ?? '—')) ?></div>
    </div>
    <div class="summary-item">
        <div class="summary-title">คะแนนเฉลี่ย (งวดล่าสุด)</div>
        <div class="summary-value"><?= e((string) ($summary['csat']['value'] ?? '-')) ?></div>
        <div class="summary-delta">เทียบงวดก่อน <?= e((string) ($summary['csat']['delta_label'] ?? '—')) ?></div>
    </div>
</div>

<table class="report-table">
    <thead>
    <tr>
        <th>ช่วงเวลา</th>
        <th class="num">แจ้งซ่อม</th>
        <th class="num">ปิดงาน</th>
        <th class="num">สุทธิ</th>
        <th class="num">SLA ตรงเวลา</th>
        <th class="num">เวลาซ่อมเฉลี่ย (ชม.)</th>
        <th class="num">คะแนนเฉลี่ย</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($periods ?? []) as $period): ?>
        <tr>
            <td><?= e((string) ($period['label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($period['created'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($period['resolved'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($period['net'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($period['sla_pct_label'] ?? '-')) ?><?php if ((int) ($period['sla_base'] ?? 0) > 0): ?> <small>(<?= e((string) $period['sla_base']) ?>)</small><?php endif; ?></td>
            <td class="num"><?= e((string) ($period['mttr_hours_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($period['csat_label'] ?? '-')) ?><?php if ((int) ($period['rating_count'] ?? 0) > 0): ?> <small>(<?= e((string) $period['rating_count']) ?>)</small><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</body>
</html>
