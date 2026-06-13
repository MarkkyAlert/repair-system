<section class="stack-lg">
    <section class="panel-card">
        <div class="panel-head">
            <h2 class="panel-title">เพิ่มทรัพย์สินใหม่</h2>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับไป Assets', 'variant' => 'secondary', 'href' => '/assets']) ?>
            </div>
        </div>
        <p class="body-text">เมื่อบันทึก Asset ระบบจะสร้าง QR token ให้โดยอัตโนมัติ</p>
    </section>

    <section class="panel-card stack-md">
        <?php if (!empty($errorMessage)): ?>
            <div class="stack-md">
                <span class="badge badge-danger">Error</span>
                <p class="helper-text"><?= e((string) $errorMessage) ?></p>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/assets')) ?>" class="stack-lg">
            <?= csrf_field() ?>

            <div class="content-grid">
                <div class="field-group">
                    <label for="asset_code" class="field-label">Asset Code</label>
                    <input id="asset_code" name="asset_code" type="text" class="input" maxlength="60" value="<?= e((string) ($form['defaults']['asset_code'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="name" class="field-label">Asset Name</label>
                    <input id="name" name="name" type="text" class="input" maxlength="200" value="<?= e((string) ($form['defaults']['name'] ?? '')) ?>">
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="serial_number" class="field-label">Serial Number</label>
                    <input id="serial_number" name="serial_number" type="text" class="input" value="<?= e((string) ($form['defaults']['serial_number'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="asset_category_id" class="field-label">Category</label>
                    <select id="asset_category_id" name="asset_category_id" class="input">
                        <option value="">เลือก Category</option>
                        <?php foreach (($form['categories'] ?? []) as $category): ?>
                            <option value="<?= e((string) $category['id']) ?>"<?= (string) ($form['defaults']['asset_category_id'] ?? '') === (string) $category['id'] ? ' selected' : '' ?>><?= e($category['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="department_id" class="field-label">Department</label>
                    <select id="department_id" name="department_id" class="input">
                        <option value="">ไม่ระบุ Department</option>
                        <?php foreach (($form['departments'] ?? []) as $department): ?>
                            <option value="<?= e((string) $department['id']) ?>"<?= (string) ($form['defaults']['department_id'] ?? '') === (string) $department['id'] ? ' selected' : '' ?>><?= e($department['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="location_id" class="field-label">Location</label>
                    <select id="location_id" name="location_id" class="input">
                        <option value="">เลือก Location</option>
                        <?php foreach (($form['locations'] ?? []) as $location): ?>
                            <option value="<?= e((string) $location['id']) ?>"<?= (string) ($form['defaults']['location_id'] ?? '') === (string) $location['id'] ? ' selected' : '' ?>><?= e($location['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="custodian_user_id" class="field-label">Custodian</label>
                    <select id="custodian_user_id" name="custodian_user_id" class="input">
                        <option value="">ไม่ระบุ Custodian</option>
                        <?php foreach (($form['custodians'] ?? []) as $custodian): ?>
                            <option value="<?= e((string) $custodian['id']) ?>"<?= (string) ($form['defaults']['custodian_user_id'] ?? '') === (string) $custodian['id'] ? ' selected' : '' ?>><?= e($custodian['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="status" class="field-label">Status</label>
                    <select id="status" name="status" class="input">
                        <?php foreach (($form['statusOptions'] ?? []) as $option): ?>
                            <option value="<?= e($option['value']) ?>"<?= (string) ($form['defaults']['status'] ?? 'active') === (string) $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="brand" class="field-label">Brand</label>
                    <input id="brand" name="brand" type="text" class="input" value="<?= e((string) ($form['defaults']['brand'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="model" class="field-label">Model</label>
                    <input id="model" name="model" type="text" class="input" value="<?= e((string) ($form['defaults']['model'] ?? '')) ?>">
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="vendor" class="field-label">Vendor</label>
                    <input id="vendor" name="vendor" type="text" class="input" value="<?= e((string) ($form['defaults']['vendor'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="purchase_date" class="field-label">Purchase Date</label>
                    <input id="purchase_date" name="purchase_date" type="date" class="input" value="<?= e((string) ($form['defaults']['purchase_date'] ?? '')) ?>">
                </div>
            </div>

            <div class="content-grid">
                <div class="field-group">
                    <label for="warranty_expires_at" class="field-label">Warranty Expires At</label>
                    <input id="warranty_expires_at" name="warranty_expires_at" type="date" class="input" value="<?= e((string) ($form['defaults']['warranty_expires_at'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="notes" class="field-label">Notes</label>
                    <textarea id="notes" name="notes" class="input" rows="4"><?= e((string) ($form['defaults']['notes'] ?? '')) ?></textarea>
                </div>
            </div>

            <div class="button-row">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'บันทึก Asset', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ยกเลิก', 'variant' => 'secondary', 'href' => '/assets']) ?>
            </div>
        </form>
    </section>
</section>
