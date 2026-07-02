<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบงานซ่อมบำรุง PDF</title>
    <style>
        @page { margin: 24px; }
        body { font-family: 'sarabun', sans-serif; font-size: 11px; color: #102a3a; }
        h1, h2, h3, p { margin: 0; }
        .stack { margin-bottom: 14px; }
        .grid { width: 100%; }
        .grid td { width: 50%; vertical-align: top; padding: 6px 10px 6px 0; }
        .document-header { padding: 16px 18px; margin-bottom: 14px; background: #0a2233; color: #fff; }
        .document-header p { color: #b9d3d8; margin-top: 4px; }
        .document-kicker { color: #5eead4 !important; font-size: 9px; font-weight: bold; letter-spacing: 1px; }
        .panel { border: 1px solid #d9e5e8; border-left: 3px solid #14b8a6; padding: 12px; margin-bottom: 12px; }
        .muted { color: #607783; }
        .paragraph-space-lg { margin-top: 8px; }
        .paragraph-space-md { margin-top: 6px; }
        .label { font-size: 11px; color: #475569; }
        .value { font-size: 13px; font-weight: 500; margin-top: 2px; }
    </style>
</head>
<body>
<div class="document-header">
    <p class="document-kicker">MAINTENANCE OPERATIONS · ใบสั่งงาน</p>
    <h1>ใบงานซ่อมบำรุง</h1>
    <p>พิมพ์เมื่อ <?= e($printedAt ?? '-') ?> · กระดาษ <?= e($paperLabel ?? 'A4') ?></p>
</div>

<div class="panel">
    <div class="label">Ticket</div>
    <div class="value"><?= e((string) ($ticket['ticket_no'] ?? '-')) ?> - <?= e((string) ($ticket['title'] ?? '-')) ?></div>
    <p class="muted paragraph-space-lg"><?= e((string) ($ticket['description'] ?? '-')) ?></p>
</div>

<table class="grid panel">
    <tr>
        <td>
            <div class="label">ผู้แจ้ง</div>
            <div class="value"><?= e((string) ($ticket['requester_name'] ?? '-')) ?></div>
        </td>
        <td>
            <div class="label">ผู้จัดการ</div>
            <div class="value"><?= e((string) ($ticket['manager_name'] ?? '-')) ?></div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">หมวดหมู่</div>
            <div class="value"><?= e((string) ($ticket['category_name'] ?? '-')) ?></div>
        </td>
        <td>
            <div class="label">ช่างเทคนิค</div>
            <div class="value"><?= e((string) ($ticket['technician_name'] ?? '-')) ?></div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">สถานที่</div>
            <div class="value"><?= e((string) ($ticket['location_detail'] ?? '-')) ?></div>
        </td>
        <td>
            <div class="label">ทรัพย์สิน</div>
            <div class="value"><?= e((string) ($ticket['asset_code'] ?? '-')) ?> - <?= e((string) ($ticket['asset_name'] ?? '-')) ?></div>
        </td>
    </tr>
</table>

<table class="grid panel">
    <tr>
        <td>
            <div class="label">สถานะ</div>
            <div class="value"><?= e((string) ($ticket['status_label'] ?? '-')) ?></div>
        </td>
        <td>
            <div class="label">ความสำคัญ</div>
            <div class="value"><?= e((string) ($ticket['priority_label'] ?? '-')) ?></div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">วันที่แจ้ง</div>
            <div class="value"><?= e((string) ($ticket['requested_at'] ?? '-')) ?></div>
        </td>
        <td>
            <div class="label">วันที่แก้ไข</div>
            <div class="value"><?= e((string) ($ticket['resolved_at'] ?? '-')) ?></div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">SLA ตอบรับ</div>
            <div class="value"><?= e((string) (($ticket['sla_response']['label'] ?? '-'))) ?> / <?= e((string) (($ticket['sla_response']['target_at'] ?? '-'))) ?></div>
        </td>
        <td>
            <div class="label">SLA แก้ไข</div>
            <div class="value"><?= e((string) (($ticket['sla_resolution']['label'] ?? '-'))) ?> / <?= e((string) (($ticket['sla_resolution']['target_at'] ?? '-'))) ?></div>
        </td>
    </tr>
</table>

<div class="panel">
    <div class="label">สรุปใบงาน</div>
    <div class="value"><?= e((string) ($ticket['work_order_no'] ?? '-')) ?></div>
    <p class="muted paragraph-space-lg">การวินิจฉัย: <?= e((string) (($ticket['work_order_diagnosis_summary'] ?? '') !== '' ? $ticket['work_order_diagnosis_summary'] : '-')) ?></p>
    <p class="muted paragraph-space-md">ผลการแก้ไข: <?= e((string) (($ticket['work_order_resolution_summary'] ?? '') !== '' ? $ticket['work_order_resolution_summary'] : '-')) ?></p>
    <p class="muted paragraph-space-md">เวลาปฏิบัติงาน (นาที): <?= e((string) ($ticket['work_order_labor_minutes'] ?? 0)) ?></p>
</div>
</body>
</html>
