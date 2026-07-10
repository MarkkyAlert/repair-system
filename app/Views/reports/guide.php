<?php
// คู่มืออ่านรายงาน — เนื้อหา static (ต่อยอดจาก description + field-hint ของแต่ละหน้ารายงาน).
$groups = [
    'ภาพรวม & ผู้บริหาร' => [
        [
            'route' => '/reports', 'icon' => 'bar-chart-3', 'title' => 'รายงานรวม',
            'answers' => 'ภาพรวมปริมาณงาน + SLA ทั้งระบบในช่วงเวลาที่เลือก',
            'look' => 'การ์ด KPI 5 ตัวด้านบน (แจ้ง · ปิดงาน · เกินกำหนด · เวลาซ่อม · คะแนน)',
            'watch' => '%SLA ตรงเวลา และจำนวนงานเกินกำหนด',
        ],
        [
            'route' => '/reports/executive', 'icon' => 'star', 'title' => 'สรุปผู้บริหาร',
            'answers' => 'งวดนี้ดีขึ้นหรือแย่ลงกว่างวดก่อนแค่ไหน (พร้อมพรีเซนต์)',
            'look' => 'ลูกศร ↑↓ ของ KPI แต่ละตัวเทียบงวดก่อน',
            'watch' => 'KPI ที่ขึ้นสีแดง (ทิศทางแย่ลง)',
        ],
        [
            'route' => '/reports/trend', 'icon' => 'trending-up', 'title' => 'แนวโน้ม',
            'answers' => 'ปริมาณงาน · SLA · เวลาซ่อม · ความพึงพอใจ ดีขึ้น/แย่ลงตามเวลา',
            'look' => 'กราฟ "แจ้ง vs ปิด" ต่อช่วงเวลา',
            'watch' => 'ถ้าเส้นแจ้งอยู่เหนือเส้นปิด = งานค้างกำลังสะสม',
        ],
    ],
    'วิเคราะห์ปัญหา' => [
        [
            'route' => '/reports/sla-breach', 'icon' => 'triangle-alert', 'title' => 'วิเคราะห์ SLA เกิน',
            'answers' => 'SLA เกินกำหนดกระจุกตัวอยู่ตรงมิติไหน',
            'look' => 'มิติที่เกินกำหนดมากสุด (เรียงขึ้นบนสุดให้แล้ว)',
            'watch' => 'คอขวดคือ "ตอบรับช้า" หรือ "แก้ไขช้า"',
        ],
        [
            'route' => '/reports/problem-hotspot', 'icon' => 'map-pin', 'title' => 'พื้นที่ปัญหา',
            'answers' => 'แผนก/สถานที่ไหนมีปัญหาหนักสุด',
            'look' => 'อันดับบนสุด (คะแนนพื้นที่สูง = ปัญหาหนัก)',
            'watch' => 'พื้นที่ที่แจ้งเยอะ + เกิน SLA เยอะพร้อมกัน',
        ],
        [
            'route' => '/reports/backlog-aging', 'icon' => 'clock', 'title' => 'งานค้างตามอายุ',
            'answers' => 'งานที่ยังไม่ปิดค้างนานแค่ไหน และกระจุกตรงไหน',
            'look' => 'คอลัมน์ค้าง >30 วัน',
            'watch' => 'กลุ่มที่ค้าง >30 วันมากสุด (ควรเคลียร์ก่อน)',
        ],
    ],
    'คุณภาพ & ทีม' => [
        [
            'route' => '/reports/technician-performance', 'icon' => 'users', 'title' => 'ผลงานทีมช่าง',
            'answers' => 'โหลดงานและผลงานของช่างแต่ละคน',
            'look' => 'สัดส่วนโหลด + งานค้างปัจจุบันของแต่ละคน',
            'watch' => 'ช่างที่โหลดเกิน หรือมีงานค้างเก่านาน',
        ],
        [
            'route' => '/reports/reopen-rate', 'icon' => 'refresh-cw', 'title' => 'งานเปิดซ้ำ',
            'answers' => 'งานที่ปิดแล้วถูกเปิดซ้ำกี่ % (First-Time-Fix)',
            'look' => 'มิติที่ %เปิดซ้ำมากสุด (เรียงบนสุด)',
            'watch' => 'มิติที่ %เปิดซ้ำสูง = แก้ไม่จบ/คุณภาพต่ำ',
        ],
        [
            'route' => '/reports/csat', 'icon' => 'message-circle', 'title' => 'ความพึงพอใจ',
            'answers' => 'ลูกค้าพอใจแค่ไหน ใคร/หมวดไหนคะแนนแย่สุด',
            'look' => '%ไม่พอใจ + อ่านความคิดเห็นจริงจากผู้แจ้ง',
            'watch' => 'คะแนนเฉลี่ยต่ำ + คอมเมนต์เชิงลบ',
        ],
        [
            'route' => '/reports/asset-reliability', 'icon' => 'activity', 'title' => 'สุขภาพทรัพย์สิน',
            'answers' => 'ทรัพย์สินไหนเสี่ยง ควรซ่อมต่อหรือเปลี่ยน',
            'look' => 'อันดับที่ระบบแนะนำ "ควรเปลี่ยน" (บนสุด)',
            'watch' => 'เสียบ่อย + เวลาซ่อมสูง + ประกันหมด',
        ],
    ],
];
?>
<section class="stack-lg">
    <h1 class="sr-only">คู่มืออ่านรายงาน</h1>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'เริ่มต้นใช้งาน',
        'title' => 'คู่มืออ่านรายงาน',
        'description' => 'เริ่มดูจากไหน · แต่ละรายงานตอบคำถามอะไร · ควรเฝ้าค่าไหน',
    ]) ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">เริ่มต้นแนะนำ (สำหรับผู้เริ่มใช้)</h2>
        </div>
        <p class="helper-text">เพิ่งเริ่มใช้ระบบ? แนะนำดูตามลำดับนี้ — จากภาพรวมไล่ลงไปหาต้นตอ</p>
        <ol style="display:grid; gap:8px; padding-left:1.25rem; margin:0;">
            <li><strong>สรุปผู้บริหาร</strong> — ดูภาพรวมงวดนี้ว่าดีขึ้นหรือแย่ลง</li>
            <li><strong>แนวโน้ม</strong> — ดูทิศทางตามเวลา (กำลังดีขึ้นหรือทรุด)</li>
            <li><strong>เจาะปัญหา</strong> — วิเคราะห์ SLA เกิน · พื้นที่ปัญหา · งานค้างตามอายุ</li>
            <li><strong>คุณภาพ &amp; ทีม</strong> — งานเปิดซ้ำ · ความพึงพอใจ · ผลงานช่าง · สุขภาพทรัพย์สิน</li>
        </ol>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <div>
                <h2 class="panel-title">ดูตัวอย่างผลลัพธ์จริง</h2>
                <p class="field-hint">รวม PDF + Excel ของ 4 รายงานเด่น (สรุปผู้บริหาร · SLA · ความพึงพอใจ · แนวโน้ม) พร้อมพรีเซนต์</p>
            </div>
            <?= render_partial('partials/components/button', [
                'label' => 'ดาวน์โหลดชุดตัวอย่าง (PDF+Excel)',
                'href' => '/reports/sample-pack',
                'variant' => 'primary',
                'icon' => 'download',
            ]) ?>
        </div>
    </section>

    <?php foreach ($groups as $groupName => $reports): ?>
        <section class="stack-md">
            <p class="nav-section-label"><?= e($groupName) ?></p>
            <div class="stat-grid stat-grid-2">
                <?php foreach ($reports as $report): ?>
                    <article class="panel-card stack-md">
                        <div class="panel-head">
                            <h3 class="panel-title" style="display:flex; align-items:center; gap:8px;">
                                <?= lucide($report['icon'], 'h-5 w-5') ?>
                                <span><?= e($report['title']) ?></span>
                            </h3>
                            <?= render_partial('partials/components/button', [
                                'label' => 'เปิดรายงาน',
                                'href' => $report['route'],
                                'variant' => 'ghost',
                                'size' => 'sm',
                                'icon' => 'chevron-right',
                                'iconPosition' => 'right',
                            ]) ?>
                        </div>
                        <div style="display:grid; gap:8px;">
                            <p><span class="badge badge-info">ตอบอะไร</span> <?= e($report['answers']) ?></p>
                            <p><span class="badge badge-default">ดูก่อน</span> <?= e($report['look']) ?></p>
                            <p><span class="badge badge-warning">เฝ้า</span> <?= e($report['watch']) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</section>
