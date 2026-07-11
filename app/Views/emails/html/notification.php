<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title><?= e($heading ?? $appName ?? 'Notification') ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; background: #edf4f6; color: #102a3a; margin: 0; padding: 24px; }
        .wrapper { max-width: 640px; margin: 0 auto; background: #ffffff; border: 1px solid #d9e5e8; border-radius: 18px; overflow: hidden; }
        .header { padding: 28px; background: #0a2233; color: #ffffff; }
        .kicker { color:#5eead4; font-size:11px; font-weight:700; letter-spacing:1px; text-transform:uppercase; }
        .content { padding: 24px; }
        .meta-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .meta-table td { border-bottom: 1px solid #d9e5e8; padding: 10px 12px; vertical-align: top; }
        .meta-label { width: 160px; color: #607783; font-weight: 700; }
        .button { display: inline-block; background: #0f766e; color: #ffffff !important; text-decoration: none; padding: 12px 18px; border-radius: 10px; font-weight:700; }
        .footer { padding: 18px 24px 24px; background:#f4f8fa; color: #607783; font-size: 12px; }
        p { margin: 0 0 14px; line-height: 1.6; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">
        <?php if (!empty($logoUrl)): ?>
            <div style="margin-bottom:12px;"><img src="<?= e((string) $logoUrl) ?>" alt="<?= e($appName ?? 'Repair System') ?>" style="max-height:48px;max-width:200px;display:block;background:#ffffff;padding:6px 10px;border-radius:8px;"></div>
        <?php endif; ?>
        <?php $kicker = trim((string) ($appTagline ?? 'Maintenance Operations')); ?>
        <?php if ($kicker !== ''): ?>
            <div class="kicker"><?= e($kicker) ?></div>
        <?php endif; ?>
        <h1><?= e($appName ?? 'Repair System') ?></h1>
    </div>
    <div class="content">
        <p>สวัสดี <?= e($recipientName ?? 'ผู้ใช้งาน') ?></p>
        <p><strong><?= e($heading ?? '-') ?></strong></p>
        <p><?= e($intro ?? '-') ?></p>
        <p><?= e($message ?? '-') ?></p>

        <?php if (!empty($sections) && is_array($sections)): ?>
            <table class="meta-table">
                <tbody>
                <?php foreach ($sections as $section): ?>
                    <tr>
                        <td class="meta-label"><?= e((string) ($section['label'] ?? '-')) ?></td>
                        <td><?= nl2br(e((string) ($section['value'] ?? '-'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p>
            <a class="button" href="<?= e((string) ($ticketUrl ?? url('/dashboard'))) ?>"><?= e((string) ($buttonLabel ?? 'เปิดดูรายละเอียด')) ?></a>
        </p>
    </div>
    <div class="footer">
        <p><?= e($footerNote ?? 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้') ?></p>
    </div>
</div>
</body>
</html>
