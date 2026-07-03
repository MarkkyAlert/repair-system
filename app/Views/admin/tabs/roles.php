    <section id="tab-roles" class="panel-card stack-md admin-tab-panel" role="tabpanel">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ดูสิทธิ์ตามบทบาท</h2>
                <p class="field-hint">สรุปความสามารถจาก workflow และ routes ที่ระบบใช้อยู่จริง เป็นเอกสารอ่านอย่างเดียวสำหรับผู้ซื้อ template</p>
            </div>
            <span class="badge badge-default">อ่านอย่างเดียว</span>
        </div>

        <?php $rolePreviewData = $rolePreview ?? ['roles' => [], 'capabilities' => []]; ?>
        <div class="table-wrap">
            <table class="data-table">
                <caption class="sr-only">ตารางสิทธิ์การใช้งานตามบทบาท</caption>
                <thead>
                <tr>
                    <th>ความสามารถ</th>
                    <?php foreach (($rolePreviewData['roles'] ?? []) as $roleCode => $roleLabel): ?>
                        <th><?= e((string) $roleLabel) ?><br><span class="helper-text"><?= e((string) $roleCode) ?></span></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($rolePreviewData['capabilities'] ?? []) as $capability): ?>
                    <tr>
                        <td><?= e((string) ($capability['label'] ?? '-')) ?></td>
                        <?php foreach (($rolePreviewData['roles'] ?? []) as $roleCode => $roleLabel): ?>
                            <?php $allowed = in_array((string) $roleCode, (array) ($capability['roles'] ?? []), true); ?>
                            <td><?= $allowed ? '<span class="badge badge-success">ทำได้</span>' : '<span class="badge badge-default">ไม่ได้</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
