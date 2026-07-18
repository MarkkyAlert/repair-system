<?php $bell = notification_bell_data(); ?>
<?php $notificationItems = is_array($bell['items'] ?? null) ? $bell['items'] : []; ?>
<?php $unreadCount = (int) ($bell['unreadCount'] ?? 0); ?>
<?php $actionCount = (int) ($bell['actionCount'] ?? 0); ?>
<?php
$isActionItem = static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), ['action', 'sla'], true);
$actionItems = array_values(array_filter($notificationItems, $isActionItem));
$recentItems = array_values(array_filter($notificationItems, static fn (array $item): bool => !in_array((string) ($item['category'] ?? ''), ['action', 'sla'], true)));
?>
<div class="notification-shell" data-notification-root data-feed-url="<?= e(url('/notifications/feed')) ?>" data-index-url="<?= e(url('/notifications')) ?>">
    <button type="button" class="icon-button notification-button" aria-label="การแจ้งเตือน" aria-haspopup="dialog" aria-expanded="false" data-notification-toggle>
        <?= lucide('bell', 'h-5 w-5') ?>
        <?php if ($unreadCount > 0): ?>
            <span class="notification-dot" data-notification-count><?= e((string) $unreadCount) ?></span>
        <?php else: ?>
            <span class="notification-dot is-hidden" data-notification-count>0</span>
        <?php endif; ?>
    </button>

    <button type="button" class="notification-backdrop" aria-label="ปิดการแจ้งเตือน" hidden data-notification-backdrop></button>
    <div class="notification-menu" role="dialog" aria-modal="false" aria-label="รายการแจ้งเตือนล่าสุด" hidden data-notification-menu>
        <div class="notification-menu-head">
            <div class="notification-heading">
                <span class="notification-heading-icon"><?= lucide('bell', 'h-5 w-5') ?></span>
                <div>
                    <p class="panel-kicker">ศูนย์การแจ้งเตือน</p>
                    <h3 class="panel-title"><?= $actionCount > 0 ? e((string) $actionCount) . ' งานต้องทำ' : 'อัปเดตล่าสุด' ?></h3>
                </div>
            </div>
            <button type="button" class="notification-close" aria-label="ปิดการแจ้งเตือน" data-notification-close><?= lucide('x', 'h-5 w-5') ?></button>
        </div>

        <div class="notification-list" data-notification-list>
            <?php if ($notificationItems === []): ?>
                <div class="notification-empty">
                    <span class="notification-empty-icon"><?= lucide('check-circle', 'h-5 w-5') ?></span>
                    <div>
                        <p class="notification-title">ไม่มีข้อความใหม่</p>
                        <p class="notification-copy">ไม่มี Ticket ที่มีอัปเดตในขณะนี้</p>
                    </div>
                </div>
            <?php else: ?>
                <?php
                $renderItem = function (array $item): void { ?>
                    <a href="<?= e($item['link_url'] ?? '/notifications') ?>" class="notification-item<?= !empty($item['is_read']) ? '' : ' is-unread' ?>">
                        <span class="notification-status-dot"></span>
                        <span class="notification-item-body">
                            <span class="notification-title"><?= e((string) ($item['title'] ?? 'การแจ้งเตือน')) ?></span>
                            <span class="notification-copy"><?= e((string) ($item['message'] ?? '')) ?></span>
                            <span class="notification-meta">
                                <strong><?= e((string) ($item['category_label'] ?? 'Workflow')) ?></strong>
                                <span>·</span>
                                <span><?= e((string) ($item['relative_time'] ?? $item['created_at'] ?? '-')) ?></span>
                                <?php if ((int) ($item['event_count'] ?? 1) > 1): ?><span>· <?= e((string) $item['event_count']) ?> กิจกรรม</span><?php endif; ?>
                            </span>
                        </span>
                        <span class="notification-item-arrow" title="<?= e((string) ($item['action_label'] ?? 'เปิด Ticket')) ?>"><?= lucide('arrow-right', 'h-5 w-5') ?></span>
                    </a>
                <?php };
                ?>

                <?php if ($actionItems !== []): ?>
                    <div class="notification-section-head notification-section-head-action">
                        <?= lucide('alert-circle', 'h-4 w-4') ?>
                        <span>งานต้องทำ</span>
                        <span class="notification-section-count"><?= count($actionItems) ?></span>
                    </div>
                    <?php foreach ($actionItems as $item) $renderItem($item); ?>
                <?php endif; ?>

                <?php if ($recentItems !== []): ?>
                    <div class="notification-section-head<?= $actionItems !== [] ? ' has-separator' : '' ?>">
                        <?= lucide('clock', 'h-4 w-4') ?>
                        <span>ล่าสุด</span>
                    </div>
                    <?php foreach ($recentItems as $item) $renderItem($item); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <a href="<?= e(url('/notifications')) ?>" class="notification-menu-footer">
            <span>เปิดศูนย์การแจ้งเตือน</span>
            <?= lucide('arrow-right', 'h-5 w-5') ?>
        </a>
    </div>
</div>
