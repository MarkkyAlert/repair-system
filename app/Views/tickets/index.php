<?php
$qSearch = (string) ($filters['q'] ?? '');
$qStatus = (string) ($filters['status'] ?? '');
$qPriority = (string) ($filters['priority'] ?? '');
$qTechnician = (int) ($filters['technician_id'] ?? 0);
$qSla = (string) ($filters['sla'] ?? '');
$metricCount = static fn (string $key): int => max(0, (int) ($metrics[$key] ?? 0));
$isFilterActive = $qSearch !== '' || $qStatus !== '' || $qPriority !== '' || $qTechnician > 0 || $qSla !== '';
$isAdvancedFilterActive = $qStatus !== '' || $qPriority !== '' || $qTechnician > 0 || $qSla !== '';
$statusOptions = ['submitted' => 'ส่งแล้ว', 'pending_approval' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว', 'assigned' => 'มอบหมายแล้ว', 'accepted' => 'รับงานแล้ว', 'in_progress' => 'กำลังดำเนินการ', 'on_hold' => 'พักงาน', 'resolved' => 'รอตรวจรับ', 'completed' => 'เสร็จสิ้น', 'rejected' => 'ปฏิเสธ', 'cancelled' => 'ยกเลิก', 'closed' => 'ปิดงาน'];
$priorityOptions = ['LOW' => 'ต่ำ', 'MEDIUM' => 'กลาง', 'HIGH' => 'สูง', 'URGENT' => 'ด่วน'];
$technicianLabel = '';
foreach (($filters['technicians'] ?? []) as $technician) {
    if ($qTechnician === (int) ($technician['id'] ?? 0)) {
        $technicianLabel = (string) ($technician['label'] ?? '');
        break;
    }
}
$activeFilterChips = [];
if ($qStatus !== '') {
    $activeFilterChips[] = 'สถานะ: ' . ($statusOptions[$qStatus] ?? $qStatus);
}
if ($qPriority !== '') {
    $activeFilterChips[] = 'ความสำคัญ: ' . ($priorityOptions[$qPriority] ?? $qPriority);
}
if ($qTechnician > 0) {
    $activeFilterChips[] = 'ช่าง: ' . ($technicianLabel !== '' ? $technicianLabel : (string) $qTechnician);
}
if ($qSla === 'overdue') {
    $activeFilterChips[] = 'SLA: เกินกำหนด';
}
$urgentAlerts = [];
if ($metricCount('overdue') > 0) {
    $urgentAlerts[] = [
        'tone' => 'danger',
        'icon' => 'triangle-alert',
        'label' => 'มีงานเกิน SLA ' . $metricCount('overdue') . ' รายการ',
        'href' => '/tickets?sla=overdue',
    ];
}
if ($metricCount('pendingApproval') > 0) {
    $urgentAlerts[] = [
        'tone' => 'warning',
        'icon' => 'clock',
        'label' => 'มีงานรออนุมัติ ' . $metricCount('pendingApproval') . ' รายการ',
        'href' => '/tickets?status=pending_approval',
    ];
}
?>
<section class="stack-lg">
    <?php ob_start(); ?>
    <span class="badge badge-info"><?= e((string) ($pagination['total'] ?? 0)) ?> รายการ</span>
    <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'plus']) ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'คิวงานปฏิบัติการ',
        'title' => 'รายการแจ้งซ่อม',
        'description' => 'ติดตามสถานะ ความสำคัญ และ SLA ของงานแจ้งซ่อมทั้งหมด',
        'actions' => $heroActions,
    ]) ?>

    <div class="stat-grid">
        <?= render_partial('partials/components/card', [
            'title' => 'ทั้งหมด',
            'value' => (string) $metricCount('total'),
            'meta' => 'ดูรายการ Ticket',
            'tone' => 'default',
            'icon' => 'clipboard-list',
            'href' => '/tickets',
            'ariaLabel' => 'ดูรายการ Ticket ทั้งหมด จำนวน ' . $metricCount('total') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'รออนุมัติ',
            'value' => (string) $metricCount('pendingApproval'),
            'meta' => 'ดูรายการ Ticket',
            'tone' => 'warning',
            'icon' => 'clock',
            'href' => '/tickets?status=pending_approval',
            'ariaLabel' => 'ดูรายการ Ticket รออนุมัติ จำนวน ' . $metricCount('pendingApproval') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'กำลังดำเนินการ',
            'value' => (string) $metricCount('inProgress'),
            'meta' => 'ดูรายการ Ticket',
            'tone' => 'info',
            'icon' => 'activity',
            'href' => '/tickets?status=in_progress',
            'ariaLabel' => 'ดูรายการ Ticket กำลังดำเนินการ จำนวน ' . $metricCount('inProgress') . ' รายการ',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินกำหนด',
            'value' => (string) $metricCount('overdue'),
            'meta' => 'ดูรายการ Ticket',
            'tone' => 'danger',
            'icon' => 'triangle-alert',
            'href' => '/tickets?sla=overdue',
            'ariaLabel' => 'ดูรายการ Ticket เกิน SLA จำนวน ' . $metricCount('overdue') . ' รายการ',
        ]) ?>
    </div>

    <?php if ($urgentAlerts !== []): ?>
        <section class="operations-alert-strip" aria-label="งานด่วนที่ควรจัดการก่อน">
            <div class="operations-alert-copy">
                <span class="operations-alert-icon"><?= lucide('triangle-alert', 'h-5 w-5') ?></span>
                <div>
                    <strong>มีงานที่ควรจัดการก่อน</strong>
                    <span>เปิดรายการเพื่อจัดการ SLA และงานรออนุมัติให้ทันเวลา</span>
                </div>
            </div>
            <div class="operations-alert-actions">
                <?php foreach ($urgentAlerts as $alert): ?>
                    <a class="operations-alert-chip tone-<?= e($alert['tone']) ?>" href="<?= e(url($alert['href'])) ?>">
                        <?= lucide($alert['icon'], 'h-4 w-4') ?>
                        <span><?= e($alert['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">คิวงานทั้งหมด</h2>
            <span class="helper-text"><?= e((string) ($pagination['total'] ?? 0)) ?> รายการตรงตามตัวกรอง</span>
        </div>

        <form method="get" action="<?= e(url('/tickets')) ?>" class="ticket-filter-toolbar">
            <div class="ticket-filter-main">
                <div class="filter-search">
                    <?= lucide('search', 'h-4 w-4') ?>
                    <input type="search" name="q" value="<?= e($qSearch) ?>" placeholder="ค้นหาเลขที่ Ticket หรือหัวข้องาน..." aria-label="ค้นหาเลขที่ Ticket หรือหัวข้องาน">
                </div>
                <div class="ticket-filter-actions">
                    <button type="submit" class="btn btn-secondary btn-md"><?= lucide('filter', 'button-icon') ?><span>ค้นหาและกรอง</span></button>
                    <?php if ($isFilterActive): ?>
                        <a href="<?= e(url('/tickets')) ?>" class="btn btn-ghost btn-md"><?= lucide('x', 'button-icon') ?><span>ล้างตัวกรอง</span></a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($activeFilterChips !== []): ?>
                <div class="ticket-filter-chips" aria-label="ตัวกรองที่กำลังใช้งาน">
                    <?php foreach ($activeFilterChips as $chip): ?>
                        <span class="filter-chip"><?= e($chip) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <details class="ticket-filter-advanced"<?= $isAdvancedFilterActive ? ' open' : '' ?>>
                <summary>
                    <span><?= lucide('filter', 'h-4 w-4') ?> ตัวกรองเพิ่มเติม</span>
                    <?php if ($isAdvancedFilterActive): ?>
                        <span class="badge badge-info"><?= e((string) count($activeFilterChips)) ?> ตัวกรอง</span>
                    <?php endif; ?>
                    <span class="collapsible-chevron"><?= lucide('chevron-down', 'h-4 w-4') ?></span>
                </summary>
                <div class="ticket-filter-grid">
                    <div class="field-group">
                        <label class="field-label" for="ticket-filter-status">สถานะ</label>
                        <select id="ticket-filter-status" name="status" class="input" aria-label="กรองตามสถานะ">
                            <option value="">ทุกสถานะ</option>
                            <?php foreach ($statusOptions as $val => $lab): ?>
                                <option value="<?= e($val) ?>"<?= $qStatus === $val ? ' selected' : '' ?>><?= e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="ticket-filter-priority">ความสำคัญ</label>
                        <select id="ticket-filter-priority" name="priority" class="input" aria-label="กรองตามระดับความสำคัญ">
                            <option value="">ทุกระดับความสำคัญ</option>
                            <?php foreach ($priorityOptions as $val => $lab): ?>
                                <option value="<?= e($val) ?>"<?= $qPriority === $val ? ' selected' : '' ?>><?= e($lab) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="ticket-filter-technician">ช่างผู้รับผิดชอบ</label>
                        <select id="ticket-filter-technician" name="technician_id" class="input" aria-label="กรองตามช่าง">
                            <option value="">ทุกช่าง</option>
                            <?php foreach (($filters['technicians'] ?? []) as $technician): ?>
                                <option value="<?= e((string) $technician['id']) ?>"<?= $qTechnician === (int) $technician['id'] ? ' selected' : '' ?>><?= e($technician['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="ticket-filter-sla">SLA</label>
                        <select id="ticket-filter-sla" name="sla" class="input" aria-label="กรองตาม SLA">
                            <option value="">ทุกสถานะ SLA</option>
                            <option value="overdue"<?= $qSla === 'overdue' ? ' selected' : '' ?>>เกินกำหนด</option>
                        </select>
                    </div>
                </div>
            </details>
        </form>

        <?php if ($tickets === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clipboard-list',
                'title' => $isFilterActive ? 'ไม่พบ Ticket ตามเงื่อนไข' : 'ยังไม่มี Ticket ในคิวงาน',
                'description' => $isFilterActive ? 'ลองปรับคำค้นหรือล้างตัวกรองเพื่อดูรายการที่เกี่ยวข้อง' : 'เมื่อมีงานแจ้งซ่อมตามสิทธิ์ของคุณ รายการจะแสดงในคิวงานนี้',
            ]) ?>
        <?php else: ?>
            <div class="sr-only" id="ticket-queue-title">รายการ Ticket ที่ตรงตามตัวกรอง</div>
            <p class="sr-only" id="ticket-queue-description">รายการ Ticket ที่ตรงตามตัวกรอง จำนวน <?= e((string) ($pagination['total'] ?? 0)) ?> รายการ</p>
            <div class="ticket-queue-shell" aria-labelledby="ticket-queue-title" aria-describedby="ticket-queue-description">
                <div class="ticket-queue-head" aria-hidden="true">
                    <span>เลขที่ / หัวข้อ</span>
                    <span>ความสำคัญ</span>
                    <span>สถานะ</span>
                    <span>SLA</span>
                    <span>ผู้แจ้ง</span>
                    <span>สถานที่</span>
                    <span>วันที่แจ้ง</span>
                </div>
                <ul class="ticket-queue">
                    <?php foreach ($tickets as $ticket): ?>
                        <?php
                        $ticketHref = '/tickets/' . $ticket['id'];
                        $ticketAria = 'เปิด Ticket ' . (string) $ticket['ticket_no']
                            . ' ' . (string) $ticket['title']
                            . ' สถานะ ' . (string) $ticket['status_label']
                            . ' ความสำคัญ ' . (string) $ticket['priority_label']
                            . ' SLA ' . (string) $ticket['sla_overview_label'];
                        ?>
                        <li>
                            <a class="ticket-queue-row<?= !empty($ticket['is_overdue']) ? ' is-overdue' : '' ?>" href="<?= e(url($ticketHref)) ?>" aria-label="<?= e($ticketAria) ?>">
                                <span class="ticket-queue-main">
                                    <span class="ticket-queue-kicker">
                                        <code class="mono ticket-no"><?= e($ticket['ticket_no']) ?></code>
                                        <span class="ticket-queue-mobile-badges" aria-hidden="true">
                                            <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                                            <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                                        </span>
                                    </span>
                                    <strong><?= e($ticket['title']) ?></strong>
                                    <span class="helper-text"><?= e($ticket['category_name']) ?></span>
                                </span>
                                <span class="ticket-queue-cell ticket-queue-priority" data-label="ความสำคัญ">
                                    <?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?>
                                </span>
                                <span class="ticket-queue-cell ticket-queue-status" data-label="สถานะ">
                                    <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                                </span>
                                <span class="ticket-queue-cell ticket-queue-sla" data-label="SLA">
                                    <?= render_partial('partials/components/badge', ['label' => $ticket['sla_overview_label'], 'tone' => $ticket['sla_overview_tone']]) ?>
                                    <span class="helper-text">ตอบ: <?= e($ticket['sla_response_label']) ?> · แก้: <?= e($ticket['sla_resolution_label']) ?></span>
                                </span>
                                <span class="ticket-queue-cell ticket-queue-meta" data-label="ผู้แจ้ง"><?= e($ticket['requester_name']) ?></span>
                                <span class="ticket-queue-cell ticket-queue-meta" data-label="สถานที่"><?= e($ticket['location_name']) ?></span>
                                <span class="ticket-queue-cell ticket-queue-meta" data-label="วันที่แจ้ง"><?= e(human_date($ticket['requested_at'])) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?= render_partial('partials/components/pagination', ['pagination' => $pagination]) ?>
        <?php endif; ?>
    </section>
</section>
