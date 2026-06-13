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
                <p class="panel-kicker">Work Order</p>
                <h2 class="panel-title"><?= e($ticket['ticket_no']) ?> · <?= e($ticket['title']) ?></h2>
                <p class="body-text">พิมพ์เมื่อ <?= e($printedAt ?? '-') ?></p>
            </div>
            <div class="print-qr-box">
                <img src="<?= e($ticket['print_qr_url']) ?>" alt="QR for <?= e($ticket['ticket_no']) ?>" class="print-qr-image">
                <p class="helper-text">สแกนเพื่อเปิด ticket</p>
            </div>
        </div>

        <div class="print-grid-two">
            <section class="stack-md">
                <div>
                    <p class="panel-kicker">Requester</p>
                    <h3 class="panel-title">ข้อมูลผู้แจ้ง</h3>
                </div>
                <p class="body-text"><strong>ชื่อ:</strong> <?= e($ticket['requester_name']) ?></p>
                <p class="body-text"><strong>Email:</strong> <?= e($ticket['requester_email']) ?></p>
                <p class="body-text"><strong>Phone:</strong> <?= e($ticket['requester_phone']) ?></p>
                <p class="body-text"><strong>Location:</strong> <?= e($ticket['location_detail']) ?></p>
                <p class="body-text"><strong>Asset:</strong> <?= e($ticket['asset_code']) ?> - <?= e($ticket['asset_name']) ?></p>
            </section>

            <section class="stack-md">
                <div>
                    <p class="panel-kicker">Assignment</p>
                    <h3 class="panel-title">การมอบหมายงาน</h3>
                </div>
                <p class="body-text"><strong>Manager:</strong> <?= e($ticket['manager_name']) ?></p>
                <p class="body-text"><strong>Technician:</strong> <?= e($ticket['technician_name']) ?></p>
                <p class="body-text"><strong>Work Order:</strong> <?= e($ticket['work_order_no']) ?></p>
                <p class="body-text"><strong>Status:</strong> <?= e($ticket['status_label']) ?> / <?= e($ticket['approval_label']) ?></p>
                <p class="body-text"><strong>Priority:</strong> <?= e($ticket['priority_label']) ?></p>
            </section>
        </div>

        <section class="stack-md">
            <div>
                <p class="panel-kicker">Problem</p>
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
                <p class="body-text"><strong>Response:</strong> <?= e($ticket['sla_response']['label'] ?? '-') ?> · Due <?= e($ticket['sla_response']['target_at'] ?? '-') ?></p>
                <p class="body-text"><strong>Resolution:</strong> <?= e($ticket['sla_resolution']['label'] ?? '-') ?> · Due <?= e($ticket['sla_resolution']['target_at'] ?? '-') ?></p>
                <p class="body-text"><strong>Overview:</strong> <?= e($ticket['sla_overview']['label'] ?? '-') ?></p>
            </section>

            <section class="stack-md">
                <div>
                    <p class="panel-kicker">Work Notes</p>
                    <h3 class="panel-title">คำสั่งงาน / ผลการดำเนินงาน</h3>
                </div>
                <p class="body-text"><strong>Instructions:</strong> <?= e($ticket['work_order_instructions']) ?></p>
                <p class="body-text"><strong>Diagnosis:</strong> <?= e($ticket['work_order_diagnosis_summary']) ?></p>
                <p class="body-text"><strong>Resolution:</strong> <?= e($ticket['work_order_resolution_summary']) ?></p>
                <p class="body-text"><strong>Labor minutes:</strong> <?= e((string) $ticket['work_order_labor_minutes']) ?></p>
            </section>
        </div>

        <section class="stack-md">
            <div>
                <p class="panel-kicker">Timeline</p>
                <h3 class="panel-title">เวลาสำคัญ</h3>
            </div>
            <div class="print-grid-two">
                <p class="body-text"><strong>Requested:</strong> <?= e($ticket['requested_at']) ?></p>
                <p class="body-text"><strong>Approved:</strong> <?= e($ticket['approved_at']) ?></p>
                <p class="body-text"><strong>Assigned:</strong> <?= e($ticket['assigned_at']) ?></p>
                <p class="body-text"><strong>First response:</strong> <?= e($ticket['first_response_at']) ?></p>
                <p class="body-text"><strong>Started:</strong> <?= e($ticket['started_at']) ?></p>
                <p class="body-text"><strong>Resolved:</strong> <?= e($ticket['resolved_at']) ?></p>
                <p class="body-text"><strong>Completed:</strong> <?= e($ticket['completed_at']) ?></p>
                <p class="body-text"><strong>Ticket URL:</strong> <?= e($ticket['ticket_url']) ?></p>
            </div>
        </section>

        <section class="signature-grid">
            <div class="signature-box"><span>ผู้ปฏิบัติงาน</span><strong>ลงชื่อ __________________________</strong><small>วันที่ ______ / ______ / ______</small></div>
            <div class="signature-box"><span>ผู้ตรวจรับงาน</span><strong>ลงชื่อ __________________________</strong><small>วันที่ ______ / ______ / ______</small></div>
        </section>
    </section>
</section>
