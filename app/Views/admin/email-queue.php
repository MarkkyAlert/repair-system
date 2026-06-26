<?php
$statusOptions = [
    '' => 'ทุกสถานะ',
    'queued' => 'รอส่ง',
    'processing' => 'กำลังประมวลผล',
    'sent' => 'ส่งแล้ว',
    'failed' => 'ล้มเหลว',
];
$statusTone = [
    'queued' => 'info',
    'processing' => 'warning',
    'sent' => 'success',
    'failed' => 'danger',
];
$activeStatus = (string) ($selectedStatus ?? '');
$tabUrl = static function (string $status): string {
    $query = $status !== '' ? '?status=' . rawurlencode($status) : '';
    return url('/admin/email-queue' . $query);
};
?>
<section class="stack-lg">
    <h1 class="sr-only">คิวอีเมล — ตรวจสอบและลองส่งใหม่</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => 'คิวอีเมล',
        'description' => 'ดูสถานะการส่งอีเมลและลองส่งซ้ำเมื่อเกิดความล้มเหลว',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าตั้งค่า',
            'variant' => 'secondary',
            'href' => '/admin',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <div class="stat-grid stat-grid-4">
        <?= render_partial('partials/components/card', [
            'title' => 'รอส่ง',
            'value' => (string) ($totals['queued'] ?? 0),
            'meta' => 'พร้อมส่งโดย cron',
            'tone' => 'info',
            'icon' => 'clock',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'กำลังประมวลผล',
            'value' => (string) ($totals['processing'] ?? 0),
            'meta' => 'กำลังถูก worker ดำเนินการ',
            'tone' => 'warning',
            'icon' => 'activity',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ส่งสำเร็จ',
            'value' => (string) ($totals['sent'] ?? 0),
            'meta' => 'นับสะสมในระบบ',
            'tone' => 'success',
            'icon' => 'check-circle',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'ล้มเหลว',
            'value' => (string) ($totals['failed'] ?? 0),
            'meta' => 'ครบรอบ retry แล้ว',
            'tone' => 'danger',
            'icon' => 'triangle-alert',
        ]) ?>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title"><?= e($statusOptions[$activeStatus] ?? 'ทุกสถานะ') ?></h2>
                <p class="field-hint">รายการอีเมลล่าสุด เรียงจากใหม่ไปเก่า</p>
            </div>
            <span class="badge badge-info"><?= e((string) ($pagination['total'] ?? 0)) ?> รายการ</span>
        </div>

        <nav class="preset-bar" aria-label="กรองตามสถานะ">
            <span class="helper-text">สถานะ</span>
            <?php foreach ($statusOptions as $value => $label): ?>
                <a href="<?= e($tabUrl((string) $value)) ?>" class="preset-chip<?= $activeStatus === (string) $value ? ' is-active' : '' ?>">
                    <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (($jobs ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'send',
                'title' => 'ไม่พบอีเมลในสถานะนี้',
                'description' => 'ลองเปลี่ยนตัวกรองสถานะ หรือรอให้ระบบจัดคิวอีเมลใหม่',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="insight-table" data-mobile-card>
                    <caption class="sr-only">รายการอีเมลในคิว</caption>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>สถานะ</th>
                        <th>ผู้รับ</th>
                        <th>หัวข้อ</th>
                        <th>ครั้งที่</th>
                        <th>เวลาล่าสุด</th>
                        <th>ข้อผิดพลาด</th>
                        <th>การจัดการ</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <?php $jobStatus = (string) ($job['status'] ?? 'queued'); ?>
                        <tr>
                            <td data-label="#"><code class="mono">#<?= e((string) ($job['id'] ?? 0)) ?></code></td>
                            <td data-label="สถานะ"><?= render_partial('partials/components/badge', [
                                'label' => $statusOptions[$jobStatus] ?? $jobStatus,
                                'tone' => $statusTone[$jobStatus] ?? 'default',
                            ]) ?></td>
                            <td data-label="ผู้รับ">
                                <strong><?= e((string) ($job['to_name'] ?? '-')) ?></strong>
                                <p class="helper-text"><?= e((string) ($job['to_email'] ?? '-')) ?></p>
                            </td>
                            <td data-label="หัวข้อ"><?= e((string) ($job['subject'] ?? '-')) ?></td>
                            <td data-label="ครั้งที่"><?= e((string) ($job['attempts'] ?? 0)) ?> / <?= e((string) ($job['max_attempts'] ?? 0)) ?></td>
                            <td data-label="เวลาล่าสุด"><?= e(human_date((string) ($job['updated_at'] ?? $job['created_at'] ?? ''))) ?></td>
                            <td data-label="ข้อผิดพลาด">
                                <?php $err = trim((string) ($job['error_message'] ?? '')); ?>
                                <?php if ($err !== ''): ?>
                                    <span class="helper-text" title="<?= e($err) ?>"><?= e(mb_strimwidth($err, 0, 60, '…')) ?></span>
                                <?php else: ?>
                                    <span class="helper-text">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="การจัดการ">
                                <?php if (in_array($jobStatus, ['failed', 'sent'], true)): ?>
                                    <form method="post" action="<?= e(url('/admin/email-queue/' . (int) $job['id'] . '/retry')) ?>">
                                        <?= csrf_field() ?>
                                        <?= render_partial('partials/components/button', [
                                            'type' => 'submit',
                                            'label' => 'ลองส่งใหม่',
                                            'variant' => 'secondary',
                                            'icon' => 'refresh-cw',
                                            'size' => 'sm',
                                        ]) ?>
                                    </form>
                                <?php else: ?>
                                    <span class="helper-text">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
