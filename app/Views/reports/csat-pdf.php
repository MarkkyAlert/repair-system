<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>CSAT / ความพึงพอใจ PDF</title>
    <style>
        @page { margin: 22px; }
        body { font-family: 'sarabun', sans-serif; font-size: 10px; color: #102a3a; }
        h1, h2, p { margin: 0; }
        .stack { margin-bottom: 14px; }
        .brand-header { padding: 15px 18px; margin-bottom: 14px; background: #0a2233; color: #ffffff; }
        .brand-kicker { color: #fcd34d; font-size: 8px; font-weight: bold; letter-spacing: 1px; }
        .brand-title { font-size: 22px; margin-top: 3px; }
        .brand-meta { color: #b9d3d8; margin-top: 4px; }
        .meta-table, .report-table, .dist-table { width: 100%; border-collapse: collapse; }
        .meta-table { background: #f4f8fa; border: 1px solid #d9e5e8; }
        .meta-table td { padding: 6px 8px; vertical-align: top; }
        .report-table th, .report-table td, .dist-table th, .dist-table td { border-bottom: 1px solid #d9e5e8; padding: 5px 6px; vertical-align: top; text-align: left; }
        .report-table th, .dist-table th { background: #0f766e; color: #ffffff; font-size: 8px; font-weight: 700; }
        .report-table tr:nth-child(even) td, .dist-table tr:nth-child(even) td { background: #f7fafb; }
        .summary-grid { width: 100%; margin-top: 10px; }
        .summary-item { display: inline-block; width: 24%; margin-right: .6%; padding: 8px; border: 1px solid #d9e5e8; border-top: 3px solid #14b8a6; box-sizing: border-box; }
        .summary-item.danger { border-top-color: #ef4444; }
        .summary-item.success { border-top-color: #10b981; }
        .summary-title { font-size: 8px; color: #607783; }
        .summary-value { font-size: 17px; font-weight: 700; margin-top: 3px; color: #0a2233; }
        .section-title { font-size: 12px; font-weight: 700; margin: 4px 0 6px; color: #0a2233; }
        .num { text-align: right; }
    </style>
</head>
<body>
<div class="brand-header">
    <?= render_partial('partials/print/pdf-brand') ?>
    <p class="brand-kicker">CUSTOMER SATISFACTION (CSAT)</p>
    <h1 class="brand-title">ความพึงพอใจลูกค้า</h1>
    <p class="brand-meta">สร้างเมื่อ <?= e($generatedAt ?? '-') ?> · แยกตาม<?= e((string) ($dimensionLabel ?? 'ช่าง')) ?></p>
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
        <div class="summary-title">คะแนนเฉลี่ย (CSAT)</div>
        <div class="summary-value"><?= e((string) ($summary['avg_label'] ?? '-')) ?> / 5</div>
    </div>
    <div class="summary-item">
        <div class="summary-title">จำนวนรีวิว</div>
        <div class="summary-value"><?= e((string) ($summary['rating_count'] ?? 0)) ?></div>
    </div>
    <div class="summary-item success">
        <div class="summary-title">% พอใจ (≥4★)</div>
        <div class="summary-value"><?= e((string) ($summary['satisfied_pct_label'] ?? '-')) ?></div>
    </div>
    <div class="summary-item danger">
        <div class="summary-title">% ไม่พอใจ (≤2★)</div>
        <div class="summary-value"><?= e((string) ($summary['dissatisfied_pct_label'] ?? '-')) ?></div>
    </div>
</div>

<?php if ((int) ($summary['rating_count'] ?? 0) > 0): // ไม่มีรีวิว → ไม่แสดง distribution (ตรงกับหน้าจอ; กัน 0.0%×5 ที่ทำให้เข้าใจผิด)?>
<p class="section-title">การกระจายคะแนน</p>
<table class="dist-table stack">
    <thead>
    <tr>
        <th>คะแนน</th>
        <th class="num">จำนวนรีวิว</th>
        <th class="num">สัดส่วน</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($distribution ?? []) as $bucket): ?>
        <tr>
            <td><?= (int) ($bucket['score'] ?? 0) ?> ★</td>
            <td class="num"><?= e((string) ($bucket['count'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($bucket['pct_label'] ?? '0.0%')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<p class="section-title">ความพึงพอใจแยกตาม<?= e((string) ($dimensionLabel ?? 'ช่าง')) ?> (คะแนนน้อยสุดก่อน)</p>
<table class="report-table">
    <thead>
    <tr>
        <th><?= e((string) ($dimensionLabel ?? 'ช่าง')) ?></th>
        <th class="num">คะแนนเฉลี่ย</th>
        <th class="num">จำนวนรีวิว</th>
        <th class="num">%พอใจ (≥4★)</th>
        <th class="num">%ไม่พอใจ (≤2★)</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($rows ?? []) as $row): ?>
        <tr>
            <td><?= e((string) ($row['label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['avg_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['rating_count'] ?? 0)) ?></td>
            <td class="num"><?= e((string) ($row['satisfied_pct_label'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($row['dissatisfied_pct_label'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (!empty($feedback ?? [])): // ความคิดเห็นจากผู้ใช้ — ให้ตรงกับหน้าจอ (export parity) ?>
<p class="section-title">ความคิดเห็นจากผู้ใช้ (คะแนนน้อยสุดก่อน)</p>
<table class="report-table">
    <thead>
    <tr>
        <th>เลขที่ Ticket</th>
        <th class="num">คะแนน</th>
        <th>ความคิดเห็น</th>
        <th>ช่าง</th>
        <th>วันที่</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach (($feedback ?? []) as $fb): ?>
        <tr>
            <td><?= e((string) ($fb['ticket_no'] ?? '-')) ?></td>
            <td class="num"><?= e((string) ($fb['score'] ?? '-')) ?></td>
            <td><?= e((string) ($fb['feedback'] ?? '')) ?></td>
            <td><?= e((string) ($fb['technician_name'] ?? '-')) ?></td>
            <td><?= e((string) ($fb['created_at'] ?? '-')) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</body>
</html>
