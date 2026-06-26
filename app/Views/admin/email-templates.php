<section class="stack-lg">
    <h1 class="sr-only">ตั้งค่าข้อความอีเมล — รายการ template ของระบบ</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => 'ตั้งค่าข้อความอีเมล',
        'description' => 'แก้ heading / intro / footer ต่อ template โดยไม่ต้องแก้โค้ด ใช้ default ถ้าไม่ตั้งค่า',
        'actions' => render_partial('partials/components/button', [
            'label' => 'กลับหน้าตั้งค่า',
            'variant' => 'secondary',
            'href' => '/admin',
            'icon' => 'arrow-left',
        ]),
    ]) ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">Template ทั้งหมด</h2>
                <p class="field-hint">คลิก "แก้ไข" เพื่อปรับข้อความต่อ template · "customized" หมายถึงมีค่า override อยู่</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($registry)) ?> templates</span>
        </div>

        <div class="table-wrap">
            <table class="insight-table" data-mobile-card>
                <thead>
                <tr>
                    <th>Template</th>
                    <th>คำอธิบาย</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registry as $key => $meta): ?>
                    <tr>
                        <td data-label="Template"><code class="mono"><?= e($key) ?></code></td>
                        <td data-label="คำอธิบาย"><?= e((string) $meta['label']) ?></td>
                        <td data-label="สถานะ">
                            <?php if (!empty($meta['is_customized'])): ?>
                                <?= render_partial('partials/components/badge', ['label' => 'customized', 'tone' => 'warning']) ?>
                            <?php else: ?>
                                <?= render_partial('partials/components/badge', ['label' => 'default', 'tone' => 'default']) ?>
                            <?php endif; ?>
                        </td>
                        <td data-label="การจัดการ">
                            <?= render_partial('partials/components/button', [
                                'label' => 'แก้ไข',
                                'variant' => 'secondary',
                                'href' => '/admin/email-templates/' . rawurlencode($key),
                                'icon' => 'pencil',
                                'size' => 'sm',
                            ]) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
