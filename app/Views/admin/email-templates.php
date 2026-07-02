<section class="stack-lg">
    <h1 class="sr-only">ตั้งค่าข้อความอีเมล — รายการเทมเพลตของระบบ</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'ผู้ดูแลระบบ',
        'title' => 'ตั้งค่าข้อความอีเมล',
        'description' => 'แก้หัวข้อ / คำนำ / ท้ายอีเมล ต่อเทมเพลต โดยไม่ต้องแก้โค้ด ใช้ค่าเริ่มต้นถ้าไม่ตั้งค่า',
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
                <h2 class="panel-title">เทมเพลตทั้งหมด</h2>
                <p class="field-hint">คลิก "แก้ไข" เพื่อปรับข้อความต่อเทมเพลต · "ปรับแต่งแล้ว" หมายถึงมีการแก้ไขทับค่าเริ่มต้น</p>
            </div>
            <span class="badge badge-info"><?= e((string) count($registry)) ?> เทมเพลต</span>
        </div>

        <div class="table-wrap">
            <table class="insight-table" data-mobile-card>
                <thead>
                <tr>
                    <th>เทมเพลต</th>
                    <th>คำอธิบาย</th>
                    <th>สถานะ</th>
                    <th>การจัดการ</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registry as $key => $meta): ?>
                    <tr>
                        <td data-label="เทมเพลต"><code class="mono"><?= e($key) ?></code></td>
                        <td data-label="คำอธิบาย"><?= e((string) $meta['label']) ?></td>
                        <td data-label="สถานะ">
                            <?php if (!empty($meta['is_customized'])): ?>
                                <?= render_partial('partials/components/badge', ['label' => 'ปรับแต่งแล้ว', 'tone' => 'warning']) ?>
                            <?php else: ?>
                                <?= render_partial('partials/components/badge', ['label' => 'ค่าเริ่มต้น', 'tone' => 'default']) ?>
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
