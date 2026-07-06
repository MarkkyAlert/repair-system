<?php
$filterState = $filters['selected'] ?? [];
$dimensionLabel = (string) ($filters['dimensionLabel'] ?? 'ช่าง');
$distColors = [5 => '#10b981', 4 => '#84cc16', 3 => '#f59e0b', 2 => '#f97316', 1 => '#ef4444'];
?>
<section class="stack-lg">
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'เสียงลูกค้า / ควบคุมคุณภาพบริการ',
        'title' => 'ความพึงพอใจลูกค้า (CSAT)',
        'description' => 'กระจายคะแนน · เจาะว่าช่าง/หมวดไหนคะแนนแย่สุด · อ่านความคิดเห็นจริงจากผู้แจ้ง',
    ]) ?>

    <div class="action-bar">
        <div class="action-bar-left">
            <span class="helper-text">เรียงคะแนนเฉลี่ยน้อยสุดขึ้นบน — จุดที่ลูกค้าไม่พอใจควรลงไปหา root cause ก่อน</span>
        </div>
        <div class="action-bar-right">
            <?= render_partial('partials/components/button', ['label' => 'กลับไปรายงานรวม', 'variant' => 'ghost', 'href' => '/reports', 'icon' => 'chevron-left']) ?>
        </div>
    </div>

    <details class="panel-card report-filter-collapsible" open>
        <summary class="panel-head report-filter-summary">
            <h2 class="panel-title">เลือกมิติและกรองข้อมูล</h2>
            <span class="collapsible-chevron report-filter-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
        </summary>
        <div class="report-filter-body">
        <form method="get" action="<?= e(url('/reports/csat')) ?>" class="stack-md">
            <div class="dashboard-filter-grid dashboard-filter-grid-5">
                <div class="field-group">
                    <label for="dimension" class="field-label">แยกตามมิติ</label>
                    <select id="dimension" name="dimension" class="input">
                        <?php foreach (($filters['dimensionOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['dimension'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="from_date" class="field-label">วันที่เริ่มต้น</label>
                    <input id="from_date" name="from_date" type="date" class="input" value="<?= e((string) ($filterState['from_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="to_date" class="field-label">วันที่สิ้นสุด</label>
                    <input id="to_date" name="to_date" type="date" class="input" value="<?= e((string) ($filterState['to_date'] ?? '')) ?>">
                </div>
                <div class="field-group">
                    <label for="department_id" class="field-label">แผนกผู้แจ้ง</label>
                    <select id="department_id" name="department_id" class="input">
                        <?php foreach (($filters['departmentOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['department_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field-group">
                    <label for="category_id" class="field-label">หมวดหมู่งาน</label>
                    <select id="category_id" name="category_id" class="input">
                        <?php foreach (($filters['categoryOptions'] ?? []) as $option): ?>
                            <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= (string) ($filterState['category_id'] ?? '') === (string) ($option['value'] ?? '') ? ' selected' : '' ?>><?= e((string) ($option['label'] ?? '-')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="dashboard-filter-actions">
                <?= render_partial('partials/components/button', ['type' => 'submit', 'label' => 'ดูข้อมูล', 'variant' => 'primary']) ?>
                <?= render_partial('partials/components/button', ['label' => 'ล้างตัวกรอง', 'variant' => 'ghost', 'href' => '/reports/csat', 'icon' => 'x']) ?>
                <span class="helper-text">นับจากรีวิวที่ให้คะแนนในช่วงที่เลือก (ว่าง= ทั้งหมด)</span>
            </div>
        </form>
        </div>
    </details>

    <div class="stat-grid stat-grid-4 report-stat-scroll">
        <?= render_partial('partials/components/card', [
            'title' => 'คะแนนเฉลี่ย (CSAT)',
            'value' => (string) ($summary['avg_label'] ?? '-') . ' / 5',
            'meta' => 'ค่าเฉลี่ยความพึงพอใจในช่วงที่กรอง',
            'tone' => (string) ($summary['csat_tone'] ?? 'default'),
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'จำนวนรีวิว',
            'value' => number_format((int) ($summary['rating_count'] ?? 0)),
            'meta' => 'จำนวน ticket ที่ถูกให้คะแนน',
            'tone' => 'default',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => '% พอใจ (≥4★)',
            'value' => (string) ($summary['satisfied_pct_label'] ?? '-'),
            'meta' => 'สัดส่วนรีวิวคะแนน 4–5 ดาว',
            'tone' => 'success',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => '% ไม่พอใจ (≤2★)',
            'value' => (string) ($summary['dissatisfied_pct_label'] ?? '-'),
            'meta' => 'สัดส่วนรีวิวคะแนน 1–2 ดาว',
            'tone' => 'danger',
        ]) ?>
    </div>

    <?php if ((int) ($summary['rating_count'] ?? 0) > 0): ?>
    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">การกระจายคะแนน</h2>
                <p class="field-hint">จำนวนรีวิวและสัดส่วน (%) ต่อระดับคะแนน 5→1 ดาว</p>
            </div>
        </div>
        <div class="stack-sm">
            <?php foreach (($distribution ?? []) as $bucket): ?>
                <?php $score = (int) ($bucket['score'] ?? 0); ?>
                <div style="display:flex; align-items:center; gap:12px;">
                    <span style="display:inline-flex; align-items:center; gap:2px; width:52px; font-weight:600; font-variant-numeric:tabular-nums;">
                        <?= $score ?> <?= lucide('star', 'h-4 w-4') ?>
                    </span>
                    <span style="flex:1; background:var(--surface-muted); border-radius:999px; height:14px; overflow:hidden;">
                        <span style="display:block; height:100%; width:<?= (float) ($bucket['pct'] ?? 0) ?>%; min-width:<?= ((int) ($bucket['count'] ?? 0) > 0 ? '2px' : '0') ?>; background:<?= e($distColors[$score] ?? '#94a3b8') ?>; border-radius:999px;"></span>
                    </span>
                    <span style="width:130px; text-align:right; font-variant-numeric:tabular-nums;"><?= number_format((int) ($bucket['count'] ?? 0)) ?> <span class="helper-text">(<?= e((string) ($bucket['pct_label'] ?? '0.0%')) ?>)</span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($rows)): ?>
    <div class="action-bar report-export-bar">
        <div class="action-bar-left">
            <strong>ความพึงพอใจ แยกตาม<?= e($dimensionLabel) ?></strong>
            <span class="helper-text">เลือกรูปแบบไฟล์เพื่อดาวน์โหลด (Excel มีแผ่นงานความคิดเห็นด้วย · PDF · CSV)</span>
        </div>
        <div class="action-bar-right">
            <details class="export-dropdown">
                <summary class="btn btn-primary btn-md">
                    <?= lucide('download', 'button-icon') ?>
                    <span>ส่งออกรายงาน</span>
                    <?= lucide('chevron-down', 'button-icon') ?>
                </summary>
                <div class="export-dropdown-menu">
                    <?php
                    $exportHidden = '';
                    foreach (['dimension', 'from_date', 'to_date', 'department_id', 'category_id'] as $exportField) {
                        $exportValue = (string) ($filterState[$exportField] ?? '');
                        if ($exportValue !== '') {
                            $exportHidden .= '<input type="hidden" name="' . e($exportField) . '" value="' . e($exportValue) . '">';
                        }
                    }
                    ?>
                    <form method="post" action="<?= e(url('/reports/csat/export/excel')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('clipboard-list', 'button-icon') ?>
                            <span>ส่งออก Excel</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/csat/export/pdf')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('file-text', 'button-icon') ?>
                            <span>ส่งออก PDF</span>
                        </button>
                    </form>
                    <form method="post" action="<?= e(url('/reports/csat/export/csv')) ?>" class="export-dropdown-form">
                        <?= csrf_field() ?>
                        <?= $exportHidden ?>
                        <button type="submit" class="export-dropdown-item" data-export-link>
                            <?= lucide('download', 'button-icon') ?>
                            <span>ส่งออก CSV</span>
                        </button>
                    </form>
                </div>
            </details>
        </div>
    </div>
    <?php endif; ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ความพึงพอใจ แยกตาม<?= e($dimensionLabel) ?></h2>
                <p class="field-hint">คะแนนเฉลี่ย · %พอใจ = 4–5★ · %ไม่พอใจ = 1–2★ — เรียงคะแนนเฉลี่ยน้อยสุดขึ้นบน</p>
            </div>
            <?php if (!empty($rows)): ?>
                <span class="badge badge-default"><?= e((string) count($rows)) ?> รายการ</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($rows)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ความพึงพอใจ แยกตาม<?= e($dimensionLabel) ?> จัดอันดับคะแนนเฉลี่ยน้อยสุดก่อน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0"><?= e($dimensionLabel) ?></th>
                        <th data-sort-col="1" data-sort-type="number">คะแนนเฉลี่ย</th>
                        <th data-sort-col="2" data-sort-type="number">จำนวนรีวิว</th>
                        <th data-sort-col="3" data-sort-type="number">%พอใจ (≥4★)</th>
                        <th data-sort-col="4" data-sort-type="number">%ไม่พอใจ (≤2★)</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e((string) $row['label']) ?></strong></td>
                            <td><span class="badge badge-<?= e((string) $row['csat_tone']) ?>"><?= e((string) $row['avg_label']) ?></span></td>
                            <td><?= number_format((int) $row['rating_count']) ?></td>
                            <td><?= e((string) $row['satisfied_pct_label']) ?></td>
                            <td><?php if ((int) $row['dissatisfied'] > 0): ?><strong><?= e((string) $row['dissatisfied_pct_label']) ?></strong><?php else: ?><?= e((string) $row['dissatisfied_pct_label']) ?><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'star',
                'title' => 'ยังไม่มีรีวิวในเงื่อนไขนี้',
                'description' => 'เมื่อมี Ticket ที่ถูกให้คะแนนในช่วง/เงื่อนไขที่เลือก ระบบจะสรุปความพึงพอใจให้อัตโนมัติ',
            ]) ?>
        <?php endif; ?>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">เสียงจากลูกค้า (ความคิดเห็น)</h2>
                <p class="field-hint">ความคิดเห็นที่ผู้แจ้งเขียนไว้ เรียงคะแนนแย่ก่อน (แสดงสูงสุด 100 รายการ — ดาวน์โหลด Excel เพื่อดูมากขึ้น สูงสุด 500)</p>
            </div>
            <?php if (!empty($feedback)): ?>
                <span class="badge badge-default"><?= e((string) count($feedback)) ?> ความคิดเห็น</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($feedback)): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <caption class="sr-only">ความคิดเห็นจากผู้แจ้ง เรียงคะแนนน้อยสุดก่อน</caption>
                    <thead>
                    <tr>
                        <th data-sort-col="0" data-sort-type="number">คะแนน</th>
                        <th data-sort-col="1">ความคิดเห็น</th>
                        <th data-sort-col="2">ช่าง</th>
                        <th data-sort-col="3">หมวดหมู่</th>
                        <th data-sort-col="4" data-sort-type="date">วันที่</th>
                        <th data-sort-col="5">เลขที่ Ticket</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($feedback as $item): ?>
                        <tr>
                            <td><span class="badge badge-<?= e((string) $item['tone']) ?>"><?= (int) $item['score'] ?> ★</span></td>
                            <td style="max-width:420px; white-space:normal;"><?= e((string) $item['feedback']) ?></td>
                            <td><?= e((string) $item['technician_name']) ?></td>
                            <td><?= e((string) $item['category_name']) ?></td>
                            <td><?= e((string) $item['date_label']) ?></td>
                            <td>
                                <?php if ((int) $item['ticket_id'] > 0): ?>
                                    <a href="<?= e(url('/tickets/' . (int) $item['ticket_id'])) ?>"><?= e((string) $item['ticket_no']) ?></a>
                                <?php else: ?>
                                    <?= e((string) $item['ticket_no']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'message-circle',
                'title' => 'ยังไม่มีความคิดเห็น',
                'description' => 'รีวิวในเงื่อนไขนี้ยังไม่มีความคิดเห็นแนบมา',
            ]) ?>
        <?php endif; ?>
    </section>
</section>
