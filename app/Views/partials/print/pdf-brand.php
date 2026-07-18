<?php
// Shared organisation brand line for every PDF header (ticket + all report exports). Reads app_name +
// app_tagline + the uploaded logo from Admin System Settings, so a buyer who rebrands in the UI gets
// rebranded documents — no per-view PHP edits. Self-contained inline styles (dompdf), sits above each
// document's own decorative report-type kicker. (ux-refactor F2)
$pdfBrandName = trim((string) setting('app_name', config('app.name', 'Repair System')));
$pdfBrandTagline = trim((string) setting('app_tagline', ''));
$pdfBrandLogo = branding_logo_data_uri();
?>
<div style="margin-bottom: 6px; line-height: 1.25;">
    <?php if ($pdfBrandLogo !== null): ?><img src="<?= e($pdfBrandLogo) ?>" alt="" style="max-height: 22px; max-width: 150px; vertical-align: middle; margin-right: 7px;"><?php endif; ?><span style="font-size: 11px; font-weight: bold; color: inherit;"><?= e($pdfBrandName) ?></span><?php if ($pdfBrandTagline !== ''): ?><span style="font-size: 10px; color: inherit; opacity: .85;"> · <?= e($pdfBrandTagline) ?></span><?php endif; ?>
</div>
