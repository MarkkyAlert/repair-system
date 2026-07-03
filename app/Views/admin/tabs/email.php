    <section id="tab-email" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">เทมเพลตอีเมลและทดสอบ SMTP</h2>
                <p class="field-hint">ดูตัวอย่างอีเมลจริงของระบบและส่งทดสอบผ่าน MailerService เดิม โดยไม่แสดงรหัสผ่าน SMTP</p>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', [
                    'label' => 'ดูคิวอีเมล',
                    'variant' => 'secondary',
                    'href' => '/admin/email-queue',
                    'icon' => 'send',
                ]) ?>
                <?= render_partial('partials/components/button', [
                    'label' => 'แก้ template ข้อความ',
                    'variant' => 'secondary',
                    'href' => '/admin/email-templates',
                    'icon' => 'pencil',
                ]) ?>
                <span class="badge badge-info"><?= e((string) (($mailDiagnostics['driver'] ?? 'log'))) ?></span>
            </div>
        </div>

        <?php $mail = $mailDiagnostics ?? []; ?>
        <div class="content-grid">
            <div class="panel-card panel-card-teal stack-md">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title panel-title-lg">ตั้งค่าอีเมล</h3>
                        <p class="field-hint">ค่าที่อ่านจาก `.env`/config ปัจจุบัน</p>
                    </div>
                </div>
                <dl class="description-list">
                    <dt>Driver</dt><dd><?= e((string) ($mail['driver'] ?? 'log')) ?></dd>
                    <dt>Host/Port</dt><dd><?= e((string) ($mail['host'] ?? '-')) ?>:<?= e((string) ($mail['port'] ?? '-')) ?></dd>
                    <dt>Encryption</dt><dd><?= e((string) (($mail['encryption'] ?? '') !== '' ? $mail['encryption'] : 'none')) ?></dd>
                    <dt>From</dt><dd><?= e((string) ($mail['from_name'] ?? '-')) ?> &lt;<?= e((string) ($mail['from_address'] ?? '-')) ?>&gt;</dd>
                    <dt>Reply-To</dt><dd><?= e((string) (($mail['reply_to_address'] ?? '') !== '' ? $mail['reply_to_address'] : '-')) ?></dd>
                    <?php if (!empty($mail['is_log_driver'])): ?>
                        <dt>Log Path</dt><dd><code><?= e((string) ($mail['log_path'] ?? 'storage/mail-logs')) ?></code></dd>
                    <?php endif; ?>
                </dl>
            </div>

            <div class="panel-card panel-card-indigo stack-md">
                <div class="panel-head">
                    <div>
                        <h3 class="panel-title panel-title-lg">ส่งอีเมลทดสอบ</h3>
                        <p class="field-hint">ถ้าใช้ driver log ระบบจะเขียนไฟล์ JSON ใน mail log path</p>
                    </div>
                </div>
                <form method="post" action="<?= e(url('/admin/email/test')) ?>" class="stack-md">
                    <?= csrf_field() ?>
                    <div class="field-group">
                        <label class="field-label" for="test_to_email">อีเมลปลายทาง <span class="required">*</span></label>
                        <input id="test_to_email" class="input" type="email" name="to_email" required placeholder="owner@example.com" value="<?= e((string) ($currentUser['email'] ?? '')) ?>">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="test_template">เทมเพลต</label>
                        <select id="test_template" class="input" name="template">
                            <option value="password_reset">รีเซ็ตรหัสผ่าน</option>
                            <option value="notification">การแจ้งเตือน / เหตุการณ์ Ticket</option>
                        </select>
                    </div>
                    <div class="button-row">
                        <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ส่งอีเมลทดสอบ', 'variant' => 'primary', 'icon' => 'send']) ?>
                    </div>
                </form>
            </div>
        </div>

        <?php foreach (($emailPreviews ?? []) as $templateKey => $preview): ?>
            <details class="collapsible"<?= $templateKey === 'password_reset' ? ' open' : '' ?>>
                <summary class="collapsible-summary">
                    <span class="metric-icon metric-icon-sm"><?= lucide($templateKey === 'password_reset' ? 'key-round' : 'bell', 'h-4 w-4') ?></span>
                    <div class="collapsible-summary-main">
                        <span class="collapsible-title"><?= e((string) ($preview['label'] ?? $templateKey)) ?></span>
                        <span class="collapsible-subtitle"><?= e((string) ($preview['subject'] ?? '-')) ?></span>
                    </div>
                    <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                </summary>
                <div class="collapsible-body stack-md">
                    <iframe title="<?= e((string) ($preview['label'] ?? $templateKey)) ?> preview" sandbox style="width:100%;height:520px;border:1px solid rgba(148,163,184,.35);border-radius:16px;background:#fff" srcdoc="<?= e((string) ($preview['body_html'] ?? '')) ?>"></iframe>
                    <details class="collapsible">
                        <summary class="collapsible-summary">
                            <span class="collapsible-title">ข้อความสำรอง</span>
                            <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                        </summary>
                        <div class="collapsible-body">
                            <pre class="code-block"><?= e((string) ($preview['body_text'] ?? '')) ?></pre>
                        </div>
                    </details>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
