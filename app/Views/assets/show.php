<section class="stack-lg">
    <h1 class="sr-only"><?= e($asset['name']) ?> — <?= e($asset['asset_code']) ?></h1>
    <?= render_partial('partials/components/breadcrumb', [
        'items' => [
            ['label' => 'ทะเบียนทรัพย์สิน', 'href' => '/asset-registry'],
            ['label' => (string) $asset['asset_code']],
        ],
    ]) ?>
    <div class="action-bar no-print">
        <div class="action-bar-left">
            <a href="<?= e(url('/asset-registry')) ?>" class="icon-button" aria-label="กลับทะเบียนทรัพย์สิน"><?= lucide('chevrons-left', 'h-4 w-4') ?></a>
            <div>
                <code class="mono"><?= e($asset['asset_code']) ?></code>
                <strong class="action-bar-title"><?= e($asset['name']) ?></strong>
                <span class="helper-text">ข้อมูลทรัพย์สิน จุดติดตั้ง และ QR สำหรับสแกนแจ้งซ่อม</span>
                <div class="action-bar-badges">
                    <?= render_partial('partials/components/badge', ['label' => $asset['status_label'], 'tone' => $asset['status_tone']]) ?>
                </div>
            </div>
        </div>
        <div class="action-bar-right">
            <?php if (!empty($canManage)): ?>
                <?= render_partial('partials/components/button', ['label' => 'แก้ไขทรัพย์สิน', 'variant' => 'secondary', 'href' => '/asset-registry/' . $asset['id'] . '/edit', 'icon' => 'pencil']) ?>
            <?php endif; ?>
            <?= render_partial('partials/components/button', [
                'label' => 'แจ้งซ่อมจากทรัพย์สินนี้',
                'variant' => 'primary',
                'href' => $asset['prefill_ticket_url'],
                'icon' => 'zap',
            ]) ?>
        </div>
    </div>

    <div class="content-grid asset-detail-grid">
        <section class="panel-card stack-md">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">ข้อมูลทรัพย์สิน</h2>
                    <p class="field-hint">รายละเอียดหลักสำหรับค้นหา ตรวจสอบ และจัดการงานซ่อม</p>
                </div>
                <span class="metric-icon metric-icon-sm"><?= lucide('package', 'h-4 w-4') ?></span>
            </div>

            <div class="asset-description-sections">
                <section class="asset-description-section">
                    <h3>ข้อมูลหลัก</h3>
                    <dl class="asset-description-list">
                        <div><dt>หมวดหมู่</dt><dd><?= e($asset['category_name']) ?></dd></div>
                        <div><dt>เลขซีเรียล</dt><dd><?= e($asset['serial_number']) ?></dd></div>
                        <?php if ($asset['brand'] !== ''): ?>
                            <div><dt>ยี่ห้อ</dt><dd><?= e($asset['brand']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($asset['model'] !== ''): ?>
                            <div><dt>รุ่น</dt><dd><?= e($asset['model']) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($asset['vendor'] !== ''): ?>
                            <div><dt>ผู้จำหน่าย</dt><dd><?= e($asset['vendor']) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </section>

                <section class="asset-description-section">
                    <h3>ผู้ดูแลและสถานที่</h3>
                    <dl class="asset-description-list">
                        <div><dt>แผนก</dt><dd><?= e($asset['department_name']) ?></dd></div>
                        <div><dt>ผู้ดูแล</dt><dd><?= e($asset['custodian_name']) ?></dd></div>
                        <div><dt>สถานที่</dt><dd><?= e($asset['location_name']) ?></dd></div>
                    </dl>
                </section>

                <?php
                    $warrantyDate = $asset['warranty_expires_at'];
                    $warrantyExpired = null;
                    if ($warrantyDate !== '-' && $warrantyDate !== '') {
                        $warrantyParsed = DateTime::createFromFormat('d/m/Y', $warrantyDate);
                        if ($warrantyParsed) {
                            $warrantyExpired = $warrantyParsed < new DateTime('today');
                        }
                    }
                ?>
                <details class="asset-description-section asset-description-collapsible" open>
                    <summary><h3>การซื้อและประกัน</h3><?= lucide('chevron-down', 'h-3 w-3 asset-description-chevron') ?></summary>
                    <dl class="asset-description-list">
                        <div><dt>วันที่ซื้อ</dt><dd><?= e($asset['purchase_date']) ?></dd></div>
                        <div>
                            <dt>ประกันหมดอายุ</dt>
                            <dd>
                                <?= e($warrantyDate) ?>
                                <?php if ($warrantyExpired !== null): ?>
                                    <?= render_partial('partials/components/badge', [
                                        'label' => $warrantyExpired ? 'หมดประกัน' : 'อยู่ในประกัน',
                                        'tone' => $warrantyExpired ? 'danger' : 'success',
                                    ]) ?>
                                <?php endif; ?>
                            </dd>
                        </div>
                    </dl>
                </details>

                <?php if ($asset['notes'] !== ''): ?>
                    <section class="asset-description-section">
                        <h3>หมายเหตุ</h3>
                        <p class="body-text"><?= e($asset['notes']) ?></p>
                    </section>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel-card stack-md asset-qr-panel">
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">QR สำหรับแจ้งซ่อม</h2>
                    <p class="field-hint">สแกน QR เพื่อเปิดหน้าแจ้งซ่อมพร้อมข้อมูลทรัพย์สินนี้</p>
                </div>
                <span class="metric-icon metric-icon-sm"><?= lucide('qr-code', 'h-4 w-4') ?></span>
            </div>

            <div class="asset-qr-preview">
                <img src="<?= e($asset['qr_png_url']) ?>" alt="QR สำหรับทรัพย์สิน <?= e($asset['asset_code']) ?>" loading="lazy">
                <span class="badge badge-default"><?= e($asset['asset_code']) ?></span>
            </div>

            <dl class="asset-description-list asset-qr-meta">
                <div><dt>ลิงก์สแกน</dt><dd><?php if ($asset['scan_url'] !== ''): ?><a href="<?= e($asset['scan_url']) ?>" target="_blank" rel="noopener"><?= e($asset['scan_url']) ?></a><?php else: ?>-<?php endif; ?></dd></div>
                <div><dt>สร้าง QR เมื่อ</dt><dd><?= e($asset['qr_created_at']) ?></dd></div>
                <div><dt>สแกนล่าสุด</dt><dd><?= e($asset['last_scanned_at']) ?></dd></div>
            </dl>

            <div class="asset-detail-actions">
                <?= render_partial('partials/components/button', [
                    'label' => 'แจ้งซ่อมจากทรัพย์สินนี้',
                    'variant' => 'primary',
                    'href' => $asset['prefill_ticket_url'],
                    'icon' => 'zap',
                ]) ?>
                <button type="button" class="btn btn-secondary" data-print-qr-url="<?= e($asset['qr_png_url']) ?>" aria-label="พิมพ์ QR ของทรัพย์สิน <?= e($asset['asset_code']) ?>">
                    <?= lucide('printer', 'button-icon') ?>
                    <span>พิมพ์ QR</span>
                </button>
                <a href="<?= e($asset['qr_png_url']) ?>" class="btn btn-secondary" target="_blank" rel="noopener" aria-label="เปิด QR PNG ของทรัพย์สิน <?= e($asset['asset_code']) ?>">
                    <?= lucide('external-link', 'button-icon') ?>
                    <span>เปิด PNG</span>
                </a>
            </div>

            <?php if (!empty($canManage)): ?>
                <form method="post" action="<?= e(url('/asset-registry/' . $asset['id'] . '/qr/regenerate')) ?>" class="asset-maintenance-actions" data-confirm-submit="ยืนยันสร้าง QR token ใหม่? QR เดิมจะใช้งานไม่ได้อีก และต้องพิมพ์ QR ใหม่ทุกจุดที่ติดตั้งไว้">
                    <?= csrf_field() ?>
                    <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'สร้าง QR token ใหม่', 'variant' => 'danger', 'icon' => 'triangle-alert']) ?>
                    <p class="field-hint">ใช้เมื่อ QR เดิมรั่วไหลหรือต้องการเปลี่ยนลิงก์สแกน</p>
                </form>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ประวัติการแจ้งซ่อมของทรัพย์สินนี้</h2>
                <p class="field-hint">10 รายการล่าสุดที่อ้างอิงทรัพย์สินนี้ — ช่วยให้ทีมตรวจปัญหาซ้ำได้เร็วขึ้น</p>
            </div>
            <span class="badge badge-info"><?= e((string) ($recentTicketsTotal ?? 0)) ?> รายการ</span>
        </div>

        <?php if (($recentTickets ?? []) === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clipboard-list',
                'title' => 'ยังไม่มีการแจ้งซ่อมในทรัพย์สินนี้',
                'description' => 'เมื่อมีการแจ้งซ่อมที่อ้างอิงทรัพย์สินนี้ ระบบจะสรุปประวัติให้ที่นี่',
            ]) ?>
        <?php else: ?>
            <ul class="list-rows">
                <?php foreach ($recentTickets as $ticket): ?>
                    <li>
                        <a class="list-row<?= !empty($ticket['is_overdue']) ? ' is-overdue' : '' ?>" href="<?= e(url('/tickets/' . $ticket['id'])) ?>">
                            <span class="list-row-main">
                                <span class="list-row-title">
                                    <code class="mono"><?= e($ticket['ticket_no']) ?></code>
                                    <strong><?= e($ticket['title']) ?></strong>
                                </span>
                                <span class="list-row-meta"><?= e($ticket['requester_name']) ?> · <?= e($ticket['location_name']) ?> · <?= e($ticket['requested_at']) ?></span>
                            </span>
                            <span class="list-row-side">
                                <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                                <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                                <span class="list-row-arrow"><?= lucide('chevron-right', 'h-4 w-4') ?></span>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</section>
