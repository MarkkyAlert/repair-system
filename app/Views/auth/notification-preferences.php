<section class="stack-lg prefs-page">
    <h1 class="sr-only">ตั้งค่าการแจ้งเตือน — เลือกช่องทางและประเภทที่ต้องการรับ</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'บัญชีผู้ใช้งาน',
        'title' => 'ตั้งค่าการแจ้งเตือน',
        'description' => 'เลือกประเภทและช่องทางการแจ้งเตือนที่ต้องการรับ ค่าเริ่มต้นคือเปิดทั้งหมด',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าบัญชี',
            'variant' => 'secondary',
            'href' => '/profile',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <?php if (!empty($successMessage)): ?>
        <div class="auth-alert auth-alert-success" role="status">
            <span class="auth-alert-icon"><?= lucide('check-circle', 'h-4 w-4') ?></span>
            <p><?= e((string) $successMessage) ?></p>
        </div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="auth-alert auth-alert-danger" role="alert">
            <span class="auth-alert-icon"><?= lucide('triangle-alert', 'h-4 w-4') ?></span>
            <p><?= e((string) $errorMessage) ?></p>
        </div>
    <?php endif; ?>

    <section class="panel-card stack-md panel-narrow">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ช่องทางและประเภทการแจ้งเตือน</h2>
                <p class="field-hint">เปิดสวิตช์เพื่อรับการแจ้งเตือนผ่านช่องทางนั้น ปิดสวิตช์เพื่อหยุดรับเฉพาะรายการ</p>
            </div>
        </div>

        <form id="prefs-form" method="post" action="<?= e(url('/profile/notifications')) ?>" class="stack-md" data-loading-submit data-warn-unsaved>
            <?= csrf_field() ?>

            <div class="prefs-preset-row" role="group" aria-label="ตั้งค่ารวดเร็ว">
                <button type="button" class="btn btn-ghost btn-sm" data-prefs-preset="all">
                    <?= lucide('check', 'h-4 w-4') ?> เปิดทั้งหมด
                </button>
                <button type="button" class="btn btn-ghost btn-sm" data-prefs-preset="none">
                    <?= lucide('x', 'h-4 w-4') ?> ปิดทั้งหมด
                </button>
                <button type="button" class="btn btn-ghost btn-sm" data-prefs-preset="in-app-only">
                    <?= lucide('bell', 'h-4 w-4') ?> เฉพาะในระบบ
                </button>
            </div>

            <div class="table-wrap">
                <table class="insight-table notification-prefs-table">
                    <thead>
                    <tr>
                        <th scope="col">ประเภทการแจ้งเตือน</th>
                        <th scope="col" class="prefs-col">ในระบบ</th>
                        <th scope="col" class="prefs-col">อีเมล</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preferences as $type => $pref): ?>
                        <?php
                        $inAppId = 'pref_' . $type . '_in_app';
                        $emailId = 'pref_' . $type . '_email';
                        $label = (string) $pref['label'];
                        ?>
                        <tr>
                            <th scope="row" class="prefs-label-cell">
                                <span class="prefs-label-row">
                                    <strong><?= e($label) ?></strong>
                                    <?php if (!empty($pref['off_impact'])): ?>
                                        <?php $impactId = 'impact_' . $type; ?>
                                        <button type="button" class="prefs-info-icon"
                                                aria-expanded="false" aria-controls="<?= e($impactId) ?>"
                                                aria-label="ดูผลกระทบเมื่อปิด: <?= e($label) ?>">
                                            <?= lucide('info', 'h-4 w-4') ?>
                                        </button>
                                    <?php endif; ?>
                                </span>
                                <?php if (!empty($pref['hint'])): ?>
                                    <div class="prefs-event-chips" aria-label="เหตุการณ์ที่รวมอยู่">
                                        <?php foreach (array_filter(array_map('trim', explode('·', (string) $pref['hint']))) as $event): ?>
                                            <span class="prefs-event-chip"><?= e($event) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($pref['off_impact'])): ?>
                                    <p id="<?= e($impactId) ?>" class="prefs-impact-text helper-text" hidden>
                                        <?= lucide('triangle-alert', 'h-4 w-4 prefs-impact-icon') ?>
                                        <span><?= e((string) $pref['off_impact']) ?></span>
                                    </p>
                                <?php endif; ?>
                            </th>
                            <td class="prefs-toggle-cell" data-channel-label="ในระบบ">
                                <label for="<?= e($inAppId) ?>" class="prefs-switch-wrap">
                                    <input id="<?= e($inAppId) ?>" class="switch" type="checkbox"
                                           name="pref[<?= e($type) ?>][in_app]" value="1"
                                           data-channel="in_app"
                                           aria-label="แจ้งเตือนในระบบ: <?= e($label) ?>"
                                           <?= !empty($pref['in_app']) ? 'checked' : '' ?>>
                                </label>
                            </td>
                            <td class="prefs-toggle-cell" data-channel-label="อีเมล">
                                <label for="<?= e($emailId) ?>" class="prefs-switch-wrap">
                                    <input id="<?= e($emailId) ?>" class="switch" type="checkbox"
                                           name="pref[<?= e($type) ?>][email]" value="1"
                                           data-channel="email"
                                           aria-label="แจ้งเตือนทางอีเมล: <?= e($label) ?>"
                                           <?= !empty($pref['email']) ? 'checked' : '' ?>>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="button-row prefs-actions">
                <?= render_partial('partials/components/button', [
                    'type' => 'submit',
                    'label' => 'บันทึกการตั้งค่า',
                    'variant' => 'primary',
                    'icon' => 'check-circle',
                ]) ?>
                <?= render_partial('partials/components/button', [
                    'label' => 'ยกเลิก',
                    'variant' => 'ghost',
                    'href' => '/profile',
                ]) ?>
                <span class="prefs-status" data-prefs-status hidden role="status">
                    <?= lucide('info', 'prefs-status-icon') ?>
                    <span class="prefs-status-text">มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</span>
                </span>
            </div>
        </form>

        <style>
            /* Constrain whole page to 780px so hero + panel are same width */
            .prefs-page { max-width: 780px; margin-left: auto; margin-right: auto; }
            /* Compact hero scoped to this page */
            .prefs-page .page-hero { padding: 1.1rem 1.5rem; gap: 1rem; }
            .prefs-page .page-hero-title { font-size: clamp(1.2rem, 1.8vw, 1.5rem); }
            .prefs-page .page-hero-description { font-size: .85rem; margin-top: .2rem; }
            .prefs-page .page-hero::after { width: 240px; height: 240px; right: -60px; top: -100px; }

            .notification-prefs-table { width: 100%; min-width: 0; table-layout: fixed; }
            .prefs-page .table-wrap { overflow: visible; border: none; background: transparent; backdrop-filter: none; -webkit-backdrop-filter: none; border-radius: 0; }
            .notification-prefs-table .prefs-col { width: 4.5rem; text-align: center; font-size: .8rem; font-weight: 600; text-transform: none; }
            .notification-prefs-table .prefs-label-cell,
            .notification-prefs-table .prefs-label-cell *,
            .notification-prefs-table .prefs-col { text-transform: none; letter-spacing: normal; }
            .notification-prefs-table .prefs-label-cell { font-weight: 500; text-align: left; word-break: break-word; font-size: .95rem; color: var(--text); min-width: 0; }
            .notification-prefs-table .prefs-toggle-cell { text-align: center; vertical-align: middle; padding-left: .25rem; padding-right: .25rem; }
            .prefs-switch-wrap { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; cursor: pointer; }

            .prefs-label-row { display: inline-flex; align-items: center; gap: .4rem; }
            .prefs-info-icon { position: relative; display: inline-flex; align-items: center; justify-content: center; min-width: 28px; min-height: 28px; color: var(--text-muted, #94a3b8); background: transparent; border: 1px solid transparent; border-radius: 99px; padding: 2px; cursor: pointer; transition: .15s ease; }
            /* Expand the tap target to ~44px (WCAG 2.5.5) without enlarging the visible 28px icon or
               loosening the label row — an invisible, absolutely-positioned overlay carries the extra hit area. */
            .prefs-info-icon::after { content: ""; position: absolute; inset: -8px; }
            .prefs-info-icon:hover, .prefs-info-icon:focus-visible { color: var(--indigo-400, #818cf8); background: rgba(99, 102, 241, .12); border-color: rgba(99, 102, 241, .25); outline: none; }
            .prefs-info-icon[aria-expanded="true"] { color: var(--indigo-400, #818cf8); background: rgba(99, 102, 241, .15); }

            .prefs-impact-text { display: flex; align-items: flex-start; gap: .4rem; margin-top: .4rem; padding: .5rem .65rem; border-radius: 8px; background: rgba(234, 179, 8, .08); border: 1px solid rgba(234, 179, 8, .2); color: var(--text); }
            .prefs-impact-text[hidden] { display: none; }
            .prefs-impact-icon { flex-shrink: 0; color: rgb(234, 179, 8); margin-top: 2px; }

            .prefs-event-chips { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .35rem; }
            .prefs-event-chip { display: inline-flex; align-items: center; padding: .15rem .5rem; font-size: .7rem; font-weight: 500; color: var(--text-muted, #94a3b8); background: rgba(99, 102, 241, .08); border: 1px solid rgba(99, 102, 241, .15); border-radius: 99px; line-height: 1.4; }

            /* N3: Tone down Save button glow scoped to this page */
            .prefs-page .prefs-actions .btn-primary {
                box-shadow: 0 4px 12px -6px rgba(99, 102, 241, .35), inset 0 1px 0 rgba(255, 255, 255, .15);
            }
            .prefs-page .prefs-actions .btn-primary:hover {
                box-shadow: 0 6px 16px -6px rgba(99, 102, 241, .5), inset 0 1px 0 rgba(255, 255, 255, .2);
            }

            .prefs-preset-row { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; padding: .75rem; background: var(--surface-muted, rgba(255,255,255,.04)); border: 1px solid var(--glass-border, rgba(255,255,255,.08)); border-radius: 12px; }

            .prefs-actions { align-items: center; gap: .75rem; flex-wrap: wrap; padding-top: .75rem; border-top: 1px solid var(--glass-border, rgba(255,255,255,.08)); margin-top: .25rem; }
            .prefs-status { display: inline-flex; align-items: center; gap: .4rem; padding: .35rem .65rem; font-size: .8rem; color: rgb(234, 179, 8); background: rgba(234, 179, 8, .08); border: 1px solid rgba(234, 179, 8, .2); border-radius: 99px; line-height: 1.2; }
            .prefs-status[hidden] { display: none; }
            .prefs-status-icon { width: 14px; height: 14px; flex-shrink: 0; }
            .prefs-status-text { white-space: nowrap; }
            #prefs-form:not([data-dirty]) .prefs-actions button[type="submit"] { opacity: .55; cursor: not-allowed; pointer-events: none; }

            @media (max-width: 600px) {
                .prefs-page .page-hero { padding: .9rem 1rem; }
                .notification-prefs-table thead { display: none; }
                .notification-prefs-table,
                .notification-prefs-table tbody,
                .notification-prefs-table tr { display: block; width: 100%; }
                .notification-prefs-table tr { padding: .85rem 1rem; border: 1px solid var(--glass-border, rgba(255,255,255,.1)); border-radius: 12px; margin-bottom: .65rem; background: var(--surface-muted, rgba(255,255,255,.03)); }
                .notification-prefs-table .prefs-label-cell { display: block; padding: 0 0 .65rem; border-bottom: 1px solid var(--glass-border, rgba(255,255,255,.08)); margin-bottom: .5rem; }
                .notification-prefs-table .prefs-toggle-cell { display: flex; justify-content: space-between; align-items: center; padding: .4rem 0; text-align: left; }
                .notification-prefs-table .prefs-toggle-cell::before { content: attr(data-channel-label); font-weight: 500; font-size: .9rem; }
                .prefs-preset-row { font-size: .85rem; }
            }
        </style>

        <script src="<?= e(asset('js/notification-preferences.js')) ?>" defer></script>
    </section>
</section>
