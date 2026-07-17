    <section id="tab-audit" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">บันทึกการตรวจสอบ</h2>
                <p class="field-hint">อ่านอย่างเดียว: ดูว่าใครแก้ข้อมูลผู้ใช้ master data, settings, logo และอีเมลทดสอบเมื่อไหร่</p>
            </div>
            <span class="badge badge-info"><?= e((string) (int) (($auditLogs['total'] ?? 0))) ?> รายการ</span>
        </div>

        <?php
        $auditFilters = $auditFilters ?? [];
        $auditOptions = $auditFilterOptions ?? ['actions' => [], 'entityTypes' => []];
        ?>
        <form method="get" action="<?= e(url('/admin#tab-audit')) ?>" class="panel-card panel-card-sky stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label class="field-label" for="audit_action">การกระทำ</label>
                    <select id="audit_action" class="input" name="action">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($auditOptions['actions'] ?? []) as $action): ?>
                            <option value="<?= e((string) $action) ?>"<?= (string) ($auditFilters['action'] ?? '') === (string) $action ? ' selected' : '' ?>><?= e((string) $action) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label class="field-label" for="audit_entity_type">ประเภทข้อมูล</label>
                    <select id="audit_entity_type" class="input" name="entity_type">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($auditOptions['entityTypes'] ?? []) as $entityType): ?>
                            <option value="<?= e((string) $entityType) ?>"<?= (string) ($auditFilters['entity_type'] ?? '') === (string) $entityType ? ' selected' : '' ?>><?= e((string) $entityType) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label class="field-label" for="audit_user_id">ผู้ใช้</label>
                    <select id="audit_user_id" class="input" name="user_id">
                        <option value="">ทั้งหมด</option>
                        <?php foreach (($userFilterOptions ?? $users ?? []) as $auditUser): ?>
                            <option value="<?= e((string) ($auditUser['id'] ?? 0)) ?>"<?= (int) ($auditFilters['user_id'] ?? 0) === (int) ($auditUser['id'] ?? 0) ? ' selected' : '' ?>><?= e((string) ($auditUser['full_name'] ?? $auditUser['username'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label class="field-label" for="audit_date_from">จากวันที่</label>
                    <input id="audit_date_from" class="input" type="date" name="date_from" value="<?= e((string) ($auditFilters['date_from'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label class="field-label" for="audit_date_to">ถึงวันที่</label>
                    <input id="audit_date_to" class="input" type="date" name="date_to" value="<?= e((string) ($auditFilters['date_to'] ?? '')) ?>">
                </div>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'กรองบันทึกการตรวจสอบ', 'variant' => 'primary', 'icon' => 'filter']) ?>
                <?= render_partial('partials/components/button', ['href' => '/admin#tab-audit', 'label' => 'ล้างตัวกรอง', 'variant' => 'secondary', 'icon' => 'x']) ?>
            </div>
        </form>

        <?php if (empty($auditLogs['items'])): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'file-text',
                'title' => 'ยังไม่มีบันทึกการตรวจสอบ',
                'description' => 'ระบบจะเริ่มบันทึกเมื่อมี admin action สำคัญหลังจากเปิดใช้ฟีเจอร์นี้',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ตารางบันทึกการตรวจสอบ</caption>
                    <thead>
                    <tr>
                        <th>เวลา</th>
                        <th>ผู้ใช้</th>
                        <th>การกระทำ</th>
                        <th>ข้อมูล</th>
                        <th>IP</th>
                        <th>รายละเอียด</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($auditLogs['items'] ?? []) as $log): ?>
                        <?php
                        $context = json_decode((string) ($log['context'] ?? ''), true);
                        $contextSummary = is_array($context)
                            ? implode(' · ', array_slice(array_map(
                                static fn ($key, $value): string => $key . ': ' . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value),
                                array_keys($context),
                                $context
                            ), 0, 4))
                            : '';
                        ?>
                        <tr>
                            <td><?= e(human_date((string) ($log['created_at'] ?? ''))) ?></td>
                            <td><?= e((string) ($log['user_name'] ?? $log['username'] ?? 'System')) ?></td>
                            <td><span class="badge badge-info"><?= e((string) ($log['action'] ?? '-')) ?></span></td>
                            <td><?= e((string) ($log['entity_type'] ?? '-')) ?><?php if (!empty($log['entity_id'])): ?> #<?= e((string) $log['entity_id']) ?><?php endif; ?></td>
                            <td><?= e((string) ($log['ip_address'] ?? '-')) ?></td>
                            <td><span class="helper-text"><?= e($contextSummary !== '' ? $contextSummary : '-') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $auditPage = max(1, (int) ($auditLogs['page'] ?? 1));
            $auditTotalPages = max(1, (int) ($auditLogs['totalPages'] ?? 1));
            $auditQuery = $_GET ?? [];
            unset($auditQuery['audit_page']);
            $auditPageUrl = static function (int $target) use ($auditQuery): string {
                return url('/admin?' . http_build_query($auditQuery + ['audit_page' => $target]) . '#tab-audit');
            };
            ?>
            <?php if ($auditTotalPages > 1): ?>
                <nav class="pagination" aria-label="การแบ่งหน้าบันทึกการตรวจสอบ">
                    <span class="pagination-summary"><?= e((string) (int) ($auditLogs['total'] ?? 0)) ?> รายการ · หน้า <?= e((string) $auditPage) ?>/<?= e((string) $auditTotalPages) ?></span>
                    <a class="page-link<?= $auditPage <= 1 ? ' is-disabled' : '' ?>" href="<?= e($auditPageUrl(max(1, $auditPage - 1))) ?>" aria-label="หน้าก่อนหน้า"<?= $auditPage <= 1 ? ' aria-disabled="true"' : '' ?>><?= lucide('chevron-left', 'h-4 w-4') ?></a>
                    <?php for ($target = max(1, $auditPage - 2); $target <= min($auditTotalPages, $auditPage + 2); $target++): ?>
                        <a class="page-link<?= $target === $auditPage ? ' is-active' : '' ?>" href="<?= e($auditPageUrl($target)) ?>" aria-label="<?= e($target === $auditPage ? 'หน้าปัจจุบัน หน้าที่ ' . $target : 'ไปหน้าที่ ' . $target) ?>"<?= $target === $auditPage ? ' aria-current="page"' : '' ?>><?= e((string) $target) ?></a>
                    <?php endfor; ?>
                    <a class="page-link<?= $auditPage >= $auditTotalPages ? ' is-disabled' : '' ?>" href="<?= e($auditPageUrl(min($auditTotalPages, $auditPage + 1))) ?>" aria-label="หน้าถัดไป"<?= $auditPage >= $auditTotalPages ? ' aria-disabled="true"' : '' ?>><?= lucide('chevron-right', 'h-4 w-4') ?></a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
