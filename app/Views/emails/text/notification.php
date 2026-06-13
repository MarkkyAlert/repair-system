<?= ($appName ?? 'Repair System') . PHP_EOL ?>
<?= str_repeat('=', function_exists('mb_strlen') ? mb_strlen((string) ($appName ?? 'Repair System')) : strlen((string) ($appName ?? 'Repair System'))) . PHP_EOL . PHP_EOL ?>สวัสดี <?= (string) ($recipientName ?? 'ผู้ใช้งาน') . PHP_EOL . PHP_EOL ?><?= (string) ($heading ?? '-') . PHP_EOL ?><?= (string) ($intro ?? '-') . PHP_EOL . PHP_EOL ?><?= (string) ($message ?? '-') . PHP_EOL . PHP_EOL ?><?php if (!empty($sections) && is_array($sections)): ?>รายละเอียด<?php foreach ($sections as $section): ?>
- <?= (string) ($section['label'] ?? '-') ?>: <?= (string) ($section['value'] ?? '-') ?><?php endforeach; ?>

<?php endif; ?><?= (string) ($buttonLabel ?? 'เปิดดูรายละเอียด') ?>: <?= (string) ($ticketUrl ?? url('/dashboard')) . PHP_EOL . PHP_EOL ?><?= (string) ($footerNote ?? 'อีเมลฉบับนี้ถูกสร้างอัตโนมัติ กรุณาอย่าตอบกลับอีเมลนี้') . PHP_EOL ?>
