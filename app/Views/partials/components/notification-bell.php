<?php $bell = notification_bell_data(); ?>
<?php $notificationItems = is_array($bell['items'] ?? null) ? $bell['items'] : []; ?>
<?php $unreadCount = (int) ($bell['unreadCount'] ?? 0); ?>
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
    <aside class="notification-menu" role="dialog" aria-modal="false" aria-label="รายการแจ้งเตือนล่าสุด" hidden data-notification-menu>
        <div class="notification-menu-head">
            <div class="notification-heading">
                <span class="notification-heading-icon"><?= lucide('bell', 'h-5 w-5') ?></span>
                <div>
                    <p class="panel-kicker">ศูนย์การแจ้งเตือน</p>
                    <h3 class="panel-title">อัปเดตล่าสุด</h3>
                </div>
            </div>
            <button type="button" class="notification-close" aria-label="ปิดการแจ้งเตือน" data-notification-close><?= lucide('x', 'h-5 w-5') ?></button>
        </div>

        <div class="notification-list" data-notification-list>
            <?php if ($notificationItems === []): ?>
                <div class="notification-empty">
                    <span class="notification-empty-icon"><?= lucide('check-circle', 'h-5 w-5') ?></span>
                    <div>
                        <p class="notification-title">ไม่มีรายการใหม่</p>
                        <p class="notification-copy">คุณติดตามอัปเดตล่าสุดครบแล้ว</p>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($notificationItems as $item): ?>
                    <a href="<?= e($item['link_url'] ?? '/notifications') ?>" class="notification-item<?= !empty($item['is_read']) ? '' : ' is-unread' ?>">
                        <span class="notification-status-dot"></span>
                        <span class="notification-item-body">
                            <span class="notification-title"><?= e((string) ($item['title'] ?? 'การแจ้งเตือน')) ?></span>
                            <span class="notification-copy"><?= e((string) ($item['message'] ?? '')) ?></span>
                            <span class="notification-meta">
                                <strong><?= e((string) ($item['category_label'] ?? 'Workflow')) ?></strong>
                                <span>·</span>
                                <span><?= e((string) ($item['relative_time'] ?? $item['created_at'] ?? '-')) ?></span>
                            </span>
                        </span>
                        <span class="notification-item-arrow" title="<?= e((string) ($item['action_label'] ?? 'เปิด Ticket')) ?>"><?= lucide('arrow-right', 'h-5 w-5') ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <a href="<?= e(url('/notifications')) ?>" class="notification-menu-footer">
            <span>เปิดศูนย์การแจ้งเตือน</span>
            <?= lucide('arrow-right', 'h-5 w-5') ?>
        </a>
    </aside>
</div>
