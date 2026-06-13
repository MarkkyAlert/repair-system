<?php
$currentFilter = (string) ($selectedFilter ?? 'all');
$returnTo = '/notifications' . ($currentFilter === 'all' ? '' : '?filter=' . rawurlencode($currentFilter));
?>
<section class="stack-lg">
    <?php ob_start(); ?>
    <?php if (($unreadCount ?? 0) > 0): ?>
        <form method="post" action="<?= e(url('/notifications/read-all')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
            <?= render_partial('partials/components/button', [
                'type' => 'submit',
                'label' => 'อ่านทั้งหมดแล้ว',
                'variant' => 'secondary',
                'icon' => 'check-circle',
            ]) ?>
        </form>
    <?php endif; ?>
    <?php $heroActions = (string) ob_get_clean(); ?>

    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'Operations Inbox',
        'title' => 'ศูนย์การแจ้งเตือน',
        'description' => 'กล่องแจ้งเตือนของคุณ — งานที่ต้องทำและบทสนทนาในที่เดียว',
        'actions' => $heroActions,
    ]) ?>

    <section class="notification-inbox panel-card">
        <header class="notification-inbox-toolbar">
            <div>
                <div class="notification-inbox-heading">
                    <h2 class="panel-title">การแจ้งเตือนล่าสุด</h2>
                    <?php if (($unreadCount ?? 0) > 0): ?>
                        <span class="badge badge-info"><?= e((string) $unreadCount) ?> ยังไม่อ่าน</span>
                    <?php else: ?>
                        <span class="badge badge-success">อ่านครบแล้ว</span>
                    <?php endif; ?>
                </div>
            </div>
            <nav class="notification-filter-tabs" aria-label="ตัวกรองการแจ้งเตือน">
                <?php foreach (($filterOptions ?? []) as $option): ?>
                    <a href="<?= e((string) ($option['url'] ?? '/notifications')) ?>" class="notification-filter-tab<?= !empty($option['is_active']) ? ' is-active' : '' ?>"<?= !empty($option['is_active']) ? ' aria-current="page"' : '' ?>>
                        <span><?= e((string) ($option['label'] ?? '-')) ?></span>
                        <strong><?= e((string) ($option['count'] ?? 0)) ?></strong>
                    </a>
                <?php endforeach; ?>
            </nav>
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
                                <article class="notification-inbox-row<?= empty($notification['is_read']) ? ' is-unread' : '' ?>">
                                    <span class="notification-inbox-icon tone-<?= e((string) ($notification['tone'] ?? 'default')) ?>">
                                        <?= lucide((string) ($notification['icon'] ?? 'bell'), 'h-5 w-5') ?>
                                    </span>
                                    <div class="notification-inbox-content">
                                        <div class="notification-inbox-title-row">
                                            <h4><?= e((string) ($notification['title'] ?? 'การแจ้งเตือน')) ?></h4>
                                            <?= render_partial('partials/components/badge', [
                                                'label' => (string) ($notification['category_label'] ?? 'Workflow'),
                                                'tone' => (string) ($notification['tone'] ?? 'default'),
                                            ]) ?>
                                        </div>
                                        <p><?= e((string) ($notification['message'] ?? '')) ?></p>
                                        <div class="notification-inbox-meta">
                                            <span><?= e(human_date((string) ($notification['created_at'] ?? ''))) ?></span>
                                            <?php if (empty($notification['is_read'])): ?>
                                                <span class="notification-unread-label"><i></i> ยังไม่อ่าน</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="notification-inbox-actions">
                                        <?= render_partial('partials/components/button', [
                                            'label' => (string) ($notification['action_label'] ?? 'เปิด Ticket'),
                                            'variant' => empty($notification['is_read']) ? 'primary' : 'secondary',
                                            'href' => (string) ($notification['link_url'] ?? '/notifications'),
                                            'size' => 'sm',
                                            'icon' => 'arrow-right',
                                            'iconPosition' => 'right',
                                        ]) ?>
                                        <?php if (empty($notification['is_read'])): ?>
                                            <form method="post" action="<?= e(url('/notifications/' . (int) ($notification['id'] ?? 0) . '/read')) ?>">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                                <button type="submit" class="notification-mark-read">ทำเครื่องหมายว่าอ่านแล้ว</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
