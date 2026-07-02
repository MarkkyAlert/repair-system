<?php
$currentFilter = (string) ($selectedFilter ?? 'all');
$returnQuery = $_GET ?? [];
$returnTo = '/notifications' . ($returnQuery === [] ? '' : '?' . http_build_query($returnQuery));
?>
<section class="stack-lg notification-page">
    <h1 class="sr-only">ศูนย์การแจ้งเตือน — งานและบทสนทนาที่ต้องติดตาม</h1>
    <?php ob_start(); ?>
    <?php if (($unreadCount ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'อ่านข้อความใหม่ทั้งหมด',
                'variant' => 'secondary',
                'icon' => 'check-circle',
            ]) ?>
        </form>
    <?php endif; ?>
    <?php $heroActions = (string) ob_get_clean(); ?>

    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'กล่องแจ้งเตือนของฉัน',
        'title' => 'ศูนย์การแจ้งเตือน',
        'description' => 'กล่องแจ้งเตือนของคุณ — งานที่ต้องทำและบทสนทนาในที่เดียว',
        'actions' => $heroActions,
    ]) ?>

    <section class="notification-inbox panel-card">
        <header class="notification-inbox-toolbar">
            <div class="notification-inbox-summary">
                <div class="notification-inbox-heading">
                    <h2 class="panel-title">คิวงานและการแจ้งเตือน</h2>
                    <?php if (($unreadCount ?? 0) > 0): ?>
                        <span class="badge badge-info"><?= e((string) $unreadCount) ?> ข้อความใหม่</span>
                    <?php else: ?>
                        <span class="badge badge-success">ไม่มีข้อความใหม่</span>
                    <?php endif; ?>
                </div>
                <div class="notification-summary-metrics" aria-label="สรุปการแจ้งเตือน">
                    <span class="notification-summary-card tone-danger">
                        <span class="notification-summary-icon" aria-hidden="true"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
                        <span><strong><?= e((string) ($actionCount ?? 0)) ?></strong><small>รายการเร่งด่วน</small></span>
                    </span>
                    <span class="notification-summary-card tone-info">
                        <span class="notification-summary-icon" aria-hidden="true"><?= lucide('message-circle', 'h-4 w-4') ?></span>
                        <span><strong><?= e((string) ($unreadThreadCount ?? 0)) ?></strong><small>Ticket มีข้อความใหม่</small></span>
                    </span>
                    <span class="notification-summary-card tone-default">
                        <span class="notification-summary-icon" aria-hidden="true"><?= lucide('clipboard-list', 'h-4 w-4') ?></span>
                        <span><strong><?= e((string) ($threadCount ?? 0)) ?></strong><small>Ticket ทั้งหมด</small></span>
                    </span>
                </div>
            </div>
            <div class="notification-filter-shell">
                <nav class="notification-filter-tabs" aria-label="ตัวกรองการแจ้งเตือน">
                    <?php foreach (($filterOptions ?? []) as $option): ?>
                        <a href="<?= e((string) ($option['url'] ?? '/notifications')) ?>" class="notification-filter-tab<?= !empty($option['is_active']) ? ' is-active' : '' ?>"<?= !empty($option['is_active']) ? ' aria-current="page"' : '' ?>>
                            <span><?= e((string) ($option['label'] ?? '-')) ?></span>
                            <strong><?= e((string) ($option['count'] ?? 0)) ?></strong>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
        </header>

        <?php if (($groups ?? []) === []): ?>
            <div class="notification-inbox-empty">
                <?= render_partial('partials/components/empty-state', [
                    'icon' => 'check-circle',
                    'title' => $currentFilter === 'all' ? 'ยังไม่มีการแจ้งเตือน' : 'ไม่มีรายการในตัวกรองนี้',
                    'description' => $currentFilter === 'all'
                        ? 'เมื่อมีงานใหม่ การเปลี่ยนสถานะ หรือเหตุการณ์ SLA ระบบจะแสดงรายการที่นี่'
                        : 'ลองเลือกตัวกรองอื่นเพื่อดูรายการที่เกี่ยวข้อง',
                ]) ?>
            </div>
        <?php else: ?>
            <div class="notification-inbox-groups">
                <?php foreach ($groups as $group): ?>
                    <section class="notification-inbox-group" aria-labelledby="notification-group-<?= e((string) ($group['key'] ?? 'earlier')) ?>">
                        <div class="notification-group-label">
                            <h3 id="notification-group-<?= e((string) ($group['key'] ?? 'earlier')) ?>"><?= e((string) ($group['label'] ?? 'ก่อนหน้านี้')) ?></h3>
                            <span><?= e((string) count($group['items'] ?? [])) ?> รายการ</span>
                        </div>
                        <div class="notification-inbox-list">
                            <?php foreach (($group['items'] ?? []) as $notification): ?>
                                <?php
                                $notificationTone = (string) ($notification['tone'] ?? 'default');
                                $notificationCategory = (string) ($notification['category'] ?? 'workflow');
                                $notificationLink = (string) ($notification['link_url'] ?? '/notifications');
                                $actionVariant = $notificationCategory === 'sla'
                                    ? 'danger'
                                    : ($notificationCategory === 'action' ? 'primary' : 'secondary');
                                ?>
                                <article class="notification-inbox-row tone-<?= e($notificationTone) ?><?= empty($notification['is_read']) ? ' is-unread' : '' ?>">
                                    <span class="notification-inbox-icon tone-<?= e((string) ($notification['tone'] ?? 'default')) ?>">
                                        <?= lucide((string) ($notification['icon'] ?? 'bell'), 'h-5 w-5') ?>
                                    </span>
                                    <div class="notification-inbox-content">
                                        <div class="notification-inbox-title-row">
                                            <h4><a href="<?= e($notificationLink) ?>"><?= e((string) ($notification['title'] ?? 'การแจ้งเตือน')) ?></a></h4>
                                            <?= render_partial('partials/components/badge', [
                                                'label' => (string) ($notification['category_label'] ?? 'ระบบงาน'),
                                                'tone' => (string) ($notification['tone'] ?? 'default'),
                                            ]) ?>
                                            <?php if ((int) ($notification['event_count'] ?? 1) > 1): ?>
                                                <span class="notification-activity-count"><?= e((string) $notification['event_count']) ?> กิจกรรม</span>
                                            <?php endif; ?>
                                        </div>
                                        <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                                        <div class="notification-inbox-meta">
                                            <span><?= e(human_date((string) ($notification['created_at_raw'] ?? $notification['created_at'] ?? ''))) ?></span>
                                            <?php if ((string) ($notification['deadline_label'] ?? '') !== ''): ?>
                                                <span class="notification-deadline tone-<?= e((string) ($notification['tone'] ?? 'default')) ?>"><?= e((string) $notification['deadline_label']) ?></span>
                                            <?php endif; ?>
                                            <?php if ((int) ($notification['unread_event_count'] ?? 0) > 0): ?>
                                                <span class="notification-unread-label"><i></i> <?= e((string) $notification['unread_event_count']) ?> ข้อความใหม่</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="notification-inbox-actions">
                                        <?= render_partial('partials/components/button', [
                                            'label' => (string) ($notification['action_label'] ?? 'เปิด Ticket'),
                                            'variant' => $actionVariant,
                                            'href' => $notificationLink,
                                            'size' => 'sm',
                                            'icon' => 'arrow-right',
                                            'iconPosition' => 'right',
                                        ]) ?>
                                        <?php if (empty($notification['is_read'])): ?>
                                            <form method="post" action="<?= e(url((int) ($notification['ticket_id'] ?? 0) > 0
                                                ? '/notifications/ticket/' . (int) $notification['ticket_id'] . '/read'
                                                : '/notifications/' . (int) ($notification['id'] ?? 0) . '/read')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                                <button type="submit" class="notification-mark-read">อ่านข้อความใน Ticket นี้แล้ว</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
