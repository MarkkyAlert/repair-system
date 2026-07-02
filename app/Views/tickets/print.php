<section class="print-shell print-paper-<?= e($paper ?? 'a4') ?> stack-lg">
    <section class="panel-card no-print">
        <div class="panel-head">
            <div>
                <p class="panel-kicker">เอกสารใบงาน</p>
                <h1 class="panel-title">พิมพ์ใบงานซ่อมบำรุง</h1>
            </div>
            <div class="button-row">
                <?= render_partial('partials/components/button', ['label' => 'กลับ Ticket', 'variant' => 'secondary', 'href' => '/tickets/' . $ticket['id']]) ?>
                <?= render_partial('partials/components/button', ['label' => 'สลับ A4/A5', 'variant' => 'secondary', 'href' => $paper === 'a4' ? $ticket['print_a5_url'] : $ticket['print_url']]) ?>
                <button type="button" class="btn btn-primary" data-print-trigger><span>พิมพ์เอกสาร</span></button>
            </div>
        </div>
        <p class="body-text">โหมดพิมพ์ขนาด <?= e($paperLabel ?? strtoupper((string) ($paper ?? 'a4'))) ?> · สร้างเมื่อ <?= e($printedAt ?? '-') ?></p>
    </section>

    <section class="print-document panel-card stack-lg">
        <div class="print-header">
            <div>
                <p class="panel-kicker">ใบสั่งงาน</p>
                <h2 class="panel-title"><?= e($ticket['ticket_no']) ?> · <?= e($ticket['title']) ?></h2>
                <p class="body-text">พิมพ์เมื่อ <?= e($printedAt ?? '-') ?></p>
            </div>
            <div class="print-qr-box">
                <img src="<?= e($ticket['print_qr_url']) ?>" alt="QR สำหรับ <?= e($ticket['ticket_no']) ?>" class="print-qr-image">
                <p class="helper-text">สแกนเพื่อเปิด Ticket</p>
            </div>
        </div>

        <div class="print-grid-two">
            <section class="stack-md">
                <div>
                    <p class="panel-kicker">ผู้แจ้ง</p>
                    <h3 class="panel-title">ข้อมูลผู้แจ้ง</h3>
                </div>
                <p class="body-text"><strong>ชื่อ:</strong> <?= e($ticket['requester_name']) ?></p>
                <p class="body-text"><strong>อีเมล:</strong> <?= e($ticket['requester_email']) ?></p>
                <p class="body-text"><strong>เบอร์โทร:</strong> <?= e($ticket['requester_phone']) ?></p>
                <p class="body-text"><strong>สถานที่:</strong> <?= e($ticket['location_detail']) ?></p>
                <p class="body-text"><strong>ทรัพย์สิน:</strong> <?= e($ticket['asset_code']) ?> - <?= e($ticket['asset_name']) ?></p>
            </section>

            <section class="stack-md">
                <div>
                    <p class="panel-kicker">การมอบหมาย</p>
                    <h3 class="panel-title">การมอบหมายงาน</h3>
                </div>
                <p class="body-text"><strong>หัวหน้างาน:</strong> <?= e($ticket['manager_name']) ?></p>
                <p class="body-text"><strong>ช่างเทคนิค:</strong> <?= e($ticket['technician_name']) ?></p>
                <p class="body-text"><strong>ใบสั่งงาน:</strong> <?= e($ticket['work_order_no']) ?></p>
                <p class="body-text"><strong>สถานะ:</strong> <?= e($ticket['status_label']) ?> / <?= e($ticket['approval_label']) ?></p>
                <p class="body-text"><strong>ความสำคัญ:</strong> <?= e($ticket['priority_label']) ?></p>
            </section>
        </div>

        <section class="stack-md">
            <div>
                <p class="panel-kicker">ปัญหา</p>
                <h3 class="panel-title">รายละเอียดปัญหา</h3>
            </div>
            <p class="body-text"><?= nl2br(e($ticket['description'])) ?></p>
        </section>

        <div class="print-grid-two">
            <section class="stack-md">
                <div>
                    <p class="panel-kicker">SLA</p>
                    <h3 class="panel-title">เวลาเป้าหมาย</h3>
                </div>
                <p class="body-text"><strong>ตอบรับ:</strong> <?= e($ticket['sla_response']['label'] ?? '-') ?> · กำหนด <?= e($ticket['sla_response']['target_at'] ?? '-') ?></p>
                <p class="body-text"><strong>แก้ไข:</strong> <?= e($ticket['sla_resolution']['label'] ?? '-') ?> · กำหนด <?= e($ticket['sla_resolution']['target_at'] ?? '-') ?></p>
                <p class="body-text"><strong>ภาพรวม:</strong> <?= e($ticket['sla_overview']['label'] ?? '-') ?></p>
            </section>

            <section class="stack-md">
                <div>
                    <p class="panel-kicker">บันทึกงาน</p>
                    <h3 class="panel-title">คำสั่งงาน / ผลการดำเนินงาน</h3>
                </div>
                <p class="body-text"><strong>คำสั่งงาน:</strong> <?= e($ticket['work_order_instructions']) ?></p>
                <p class="body-text"><strong>การวินิจฉัย:</strong> <?= e($ticket['work_order_diagnosis_summary']) ?></p>
                <p class="body-text"><strong>การแก้ไข:</strong> <?= e($ticket['work_order_resolution_summary']) ?></p>
                <p class="body-text"><strong>เวลาปฏิบัติงาน (นาที):</strong> <?= e((string) $ticket['work_order_labor_minutes']) ?></p>
            </section>
        </div>

        <section class="stack-md">
            <div>
                <p class="panel-kicker">ลำดับเวลา</p>
                <h3 class="panel-title">เวลาสำคัญ</h3>
            </div>
            <div class="print-grid-two">
                <p class="body-text"><strong>แจ้งเมื่อ:</strong> <?= e($ticket['requested_at']) ?></p>
                <p class="body-text"><strong>อนุมัติเมื่อ:</strong> <?= e($ticket['approved_at']) ?></p>
                <p class="body-text"><strong>มอบหมายเมื่อ:</strong> <?= e($ticket['assigned_at']) ?></p>
                <p class="body-text"><strong>ตอบรับครั้งแรก:</strong> <?= e($ticket['first_response_at']) ?></p>
                <p class="body-text"><strong>เริ่มงานเมื่อ:</strong> <?= e($ticket['started_at']) ?></p>
                <p class="body-text"><strong>แก้ไขเสร็จเมื่อ:</strong> <?= e($ticket['resolved_at']) ?></p>
                <p class="body-text"><strong>ปิดงานเมื่อ:</strong> <?= e($ticket['completed_at']) ?></p>
                <p class="body-text"><strong>ลิงก์ Ticket:</strong> <?= e($ticket['ticket_url']) ?></p>
            </div>
        </section>

        <section class="signature-grid">
            <div class="signature-box"><span>ผู้ปฏิบัติงาน</span><strong>ลงชื่อ __________________________</strong><small>วันที่ ______ / ______ / ______</small></div>
            <div class="signature-box"><span>ผู้ตรวจรับงาน</span><strong>ลงชื่อ __________________________</strong><small>วันที่ ______ / ______ / ______</small></div>
        </section>
    </section>
</section>
