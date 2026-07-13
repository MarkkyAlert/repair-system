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
            'watch' => 'ถ้าเส้นแจ้งอยู่เหนือเส้นปิด = สัญญาณคร่าว ๆ ว่างานค้างอาจเพิ่ม (ดู "งานค้างตามอายุ" ประกอบ)',
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

// เกณฑ์สี (tone) — ต้องตรงกับ threshold ในโค้ด (pin ไว้ด้วย tests/cases/report_guide_test.php).
// ค่าที่ "ดี" ต่างกันตามเมตริก: SLA/completion/คะแนน สูง=ดี ; %เปิดซ้ำ/%เกิน SLA/คะแนนความเสี่ยง ต่ำ=ดี.
$toneRules = [
    ['metric' => 'SLA ตรงเวลา / SLA compliance', 'green' => '≥ 90%', 'yellow' => '75–89.9%', 'red' => '< 75%'],
    ['metric' => 'อัตราปิดงาน (completion)', 'green' => '≥ 80%', 'yellow' => '60–79.9%', 'red' => '< 60%'],
    ['metric' => 'คะแนนความพึงพอใจ (CSAT / คะแนนช่าง)', 'green' => '≥ 4.0', 'yellow' => '3.0–3.9', 'red' => '< 3.0'],
    ['metric' => '%เปิดซ้ำ (reopen) — ต่ำ = ดี', 'green' => '< 10%', 'yellow' => '10–19.9%', 'red' => '≥ 20%'],
    ['metric' => '%เกิน SLA / overdue — ต่ำ = ดี', 'green' => '< 10%', 'yellow' => '10–24.9%', 'red' => '≥ 25%'],
    ['metric' => 'คะแนนสุขภาพทรัพย์สิน — คะแนนสูง = แย่', 'green' => 'ปกติ (0–1)', 'yellow' => 'เฝ้าระวัง (2–3)', 'red' => 'ควรเปลี่ยน (≥ 4)'],
    ['metric' => 'คะแนนพื้นที่ปัญหา — คะแนนสูง = แย่', 'green' => 'ปกติ (0–1)', 'yellow' => 'เฝ้าระวัง (2)', 'red' => 'พื้นที่ปัญหา (≥ 3)'],
];

$caveats = [
    'ข้อมูลน้อยอย่าเพิ่งสรุป — "5.0 จาก 1 รีวิว" หรือ "100% จาก 1 งาน" ไม่มีนัยยะ. ดูจำนวนในวงเล็บ (เช่น "5.0 (2)" = จาก 2 รีวิว) ก่อนตัดสิน',
    '"-" = ยังไม่มีข้อมูลในช่วงนั้น (ไม่ใช่ 0%). ต่างจาก "0.0%" ที่แปลว่ามีงานแต่ทำสำเร็จ 0% — คนละความหมาย',
    'ช่วงเวลาสั้น (ไม่กี่วัน) ตัวเลขแกว่งง่ายเพราะ sample น้อย — ดูหน้าแนวโน้มหลายงวดประกอบ',
    'ตัวเลข reopen/ปิดจบรอบเดียว (FTF) ของงวดอดีต "นิ่ง" (as-reported) — งานที่ปิดในงวดแล้วถูกเปิดซ้ำข้ามไปงวดหลัง จะไม่ย้อนแก้ตัวเลขงวดเดิม',
    'Export: คอลัมน์ % ใน Excel เป็นตัวเลขจริง (pivot/sum ต่อได้) · PDF ฝังฟอนต์ไทยแล้ว (ส่งบอร์ดอ่านออก ไม่เป็นกล่อง)',
];

// อภิธานศัพท์ metric ที่ตีความผิดง่าย — นิยาม/สูตร + ทิศทางที่ดี + base. ตัวเลขคำนวณถูกแต่อ่านผิดได้ถ้าไม่รู้นิยาม.
$glossary = [
    ['term' => 'เวลาซ่อมเฉลี่ย (MTTR)', 'good' => 'ยิ่งน้อยยิ่งดี',
        'def' => 'ชม.เฉลี่ยจากเวลาแจ้งจนแก้ไขสำเร็จ — เฉพาะงานที่ปิดแล้ว (base = งานที่มีเวลาปิด). ไม่มีงานปิด = "-" ไม่ใช่ 0'],
    ['term' => 'เวลาตอบรับ (first response)', 'good' => 'ยิ่งน้อยยิ่งดี',
        'def' => 'ชม.เฉลี่ยจากเวลาแจ้งจนมีการตอบรับครั้งแรก — base = เฉพาะงานที่มีการตอบรับแล้ว'],
    ['term' => 'MTBF (ระยะห่างเฉลี่ยระหว่างการเสีย)', 'good' => 'ยิ่งมากยิ่งดี (ทน)',
        'def' => '(วันที่เสียครั้งล่าสุด − ครั้งแรก) ÷ (จำนวนครั้ง − 1) — ต้องเสีย ≥ 2 ครั้งจึงคำนวณได้ ไม่งั้นเป็น "-"'],
    ['term' => 'ปิดจบรอบเดียว / First-Time-Fix (FTF)', 'good' => 'ยิ่งสูงยิ่งดี',
        'def' => '% ของงานที่ปิดในช่วงแล้วไม่ถูกเปิดซ้ำ — เป็นคู่ตรงข้ามของ %เปิดซ้ำ (reopen)'],
    ['term' => 'สัดส่วนโหลด (workload share)', 'good' => 'สูง = แบกงานเยอะ',
        'def' => '% งานค้างของช่างคนนั้นเทียบทั้งทีม — เป็น snapshot "ตอนนี้" ไม่ขึ้นกับช่วงวันที่ที่กรอง'],
    ['term' => 'คะแนนสุขภาพทรัพย์สิน (health score)', 'good' => 'คะแนนสูง = เสี่ยง',
        'def' => 'คะแนนความเสี่ยงรวมจากหลายปัจจัย: เสียบ่อย · อายุมาก · หมดประกัน · MTBF สั้น · ซ่อมนานเฉลี่ย · กำลังซ่อม (เกณฑ์ตัดดูในตารางเกณฑ์สี)'],
    ['term' => 'สุทธิ (net) ในแนวโน้ม', 'good' => 'ต่ำ/ติดลบ = ปิดทันแจ้ง',
        'def' => 'แจ้ง − ปิด ในช่วง — สัญญาณคร่าว ๆ ว่างานค้างเพิ่ม/ลด ยังไม่หักงานที่ยกเลิก/ปฏิเสธ (ออกจากคิวโดยไม่นับว่าปิด) จึงไม่ใช่การเปลี่ยนแปลง backlog ที่แม่นยำ'],
    ['term' => 'อัตราปิดงาน (completion) — ฐานต่างกันตามหน้า', 'good' => 'ยิ่งสูงยิ่งดี',
        'def' => 'สรุปผู้บริหาร = ปิด ÷ ticket ที่แจ้งทั้งหมดในงวด (นับตามวันแจ้ง) · ผลงานทีมช่าง = ปิด ÷ งานที่รับในช่วง (นับตามวันแจ้งทั้งคู่ → ค่าย้อนหลังไม่ขยับตามงานปัจจุบัน) — คนละตัวหาร อย่านำ % ข้ามสองหน้ามาเทียบกันตรง ๆ'],
    ['term' => 'ยอดปิดงาน — นับต่างกันตามหน้า (โดยตั้งใจ)', 'good' => '—',
        'def' => 'สรุปผู้บริหาร = สถานะ "ตอนนี้" (งานที่ถูกเปิดซ้ำจะไม่นับว่าปิด) · แนวโน้ม = นับตามงวดที่ปิดจริง 1 ครั้งต่อ 1 งาน (ประวัติไม่เปลี่ยนย้อนหลัง) · ผลงานช่าง = ให้เครดิต "คนที่กดปิด" (ถ้าปิด–เปิดซ้ำ–ปิดใหม่คนละคน นับให้ทั้งสองคน) — ตัวเลขจึงต่างกันได้ตามมุมมอง'],
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

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">เกณฑ์สี — อ่านสีให้ถูก</h2>
        </div>
        <p class="helper-text">สีในตารางบอกว่าค่า <strong>ดี / เฝ้าระวัง / ต้องแก้</strong> — เกณฑ์ตัดตามนี้ (ทิศทางที่ "ดี" ต่างกันตามเมตริก)</p>
        <div class="table-wrap" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">เมตริก</th>
                        <th scope="col"><span class="badge badge-success">🟢 ปกติ</span></th>
                        <th scope="col"><span class="badge badge-warning">🟡 เฝ้าระวัง</span></th>
                        <th scope="col"><span class="badge badge-danger">🔴 ต้องแก้</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($toneRules as $rule): ?>
                        <tr>
                            <td><?= e($rule['metric']) ?></td>
                            <td><?= e($rule['green']) ?></td>
                            <td><?= e($rule['yellow']) ?></td>
                            <td><?= e($rule['red']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">ข้อควรระวังก่อนสรุป (ข้อมูลน้อย ≠ ผลจริง)</h2>
        </div>
        <ul style="display:grid; gap:8px; padding-left:1.25rem; margin:0;">
            <?php foreach ($caveats as $caveat): ?>
                <li><?= e($caveat) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">อภิธานศัพท์ — metric ที่ตีความผิดง่าย</h2>
        </div>
        <p class="helper-text">ตัวเลขคำนวณถูก แต่อ่านผิดได้ถ้าไม่รู้ว่ามันคืออะไร — นิยาม/สูตร + ทิศทางที่ "ดี"</p>
        <div class="table-wrap" tabindex="0">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">คำศัพท์</th>
                        <th scope="col">นิยาม / สูตร</th>
                        <th scope="col">ทิศทางที่ดี</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($glossary as $item): ?>
                        <tr>
                            <td><strong><?= e($item['term']) ?></strong></td>
                            <td><?= e($item['def']) ?></td>
                            <td><?= e($item['good']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
