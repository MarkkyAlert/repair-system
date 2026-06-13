<?php
$filters = $_GET ?? [];
$qSearch = trim((string) ($filters['q'] ?? ''));
$qStatus = (string) ($filters['status'] ?? '');
$qPriority = (string) ($filters['priority'] ?? '');

$filteredTickets = $tickets;
if ($qSearch !== '') {
    $needle = function_exists('mb_strtolower') ? mb_strtolower($qSearch) : strtolower($qSearch);
    $filteredTickets = array_filter($filteredTickets, static function ($t) use ($needle): bool {
        $hay = strtolower((string) ($t['ticket_no'] ?? '') . ' ' . (string) ($t['title'] ?? '') . ' ' . (string) ($t['requester_name'] ?? ''));
        return str_contains($hay, $needle);
    });
}
if ($qStatus !== '') {
    $filteredTickets = array_filter($filteredTickets, static fn ($t) => (string) ($t['status'] ?? '') === $qStatus);
}
if ($qPriority !== '') {
    $filteredTickets = array_filter($filteredTickets, static fn ($t) => (string) ($t['priority_code'] ?? $t['priority_label'] ?? '') === $qPriority);
}
$filteredTickets = array_values($filteredTickets);
?>
<section class="stack-lg">
    <?php ob_start(); ?>
    <span class="badge badge-info"><?= e((string) count($filteredTickets)) ?> รายการ</span>
    <?= render_partial('partials/components/button', ['label' => 'แจ้งปัญหาใหม่', 'variant' => 'primary', 'href' => '/tickets/create', 'icon' => 'plus']) ?>
    <?php $heroActions = (string) ob_get_clean(); ?>
    <?= render_partial('partials/components/page-header', [
        'eyebrow' => 'คิวงานปฏิบัติการ',
        'title' => 'รายการแจ้งซ่อม',
        'description' => 'คิวงานเรียงตามความสำคัญและ SLA',
        'actions' => $heroActions,
    ]) ?>

    <div class="stat-grid">
        <?= render_partial('partials/components/card', [
            'title' => 'ทั้งหมด',
            'value' => (string) ($metrics['total'] ?? 0),
            'meta' => 'ที่คุณเข้าถึงได้',
            'tone' => 'default',
            'icon' => 'clipboard-list',
            'href' => '/tickets',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'รออนุมัติ',
            'value' => (string) ($metrics['pendingApproval'] ?? 0),
            'meta' => 'รอหัวหน้างานตรวจสอบ',
            'tone' => 'warning',
            'icon' => 'clock',
            'href' => '/tickets?status=pending_approval',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'กำลังดำเนินการ',
            'value' => (string) ($metrics['inProgress'] ?? 0),
            'meta' => 'งานที่ทีมรับผิดชอบ',
            'tone' => 'info',
            'icon' => 'activity',
            'href' => '/tickets?status=in_progress',
        ]) ?>
        <?= render_partial('partials/components/card', [
            'title' => 'เกินกำหนด',
            'value' => (string) ($metrics['overdue'] ?? 0),
            'meta' => 'ต้องเร่งดำเนินการ',
            'tone' => 'danger',
            'icon' => 'triangle-alert',
        ]) ?>
    </div>

    <section class="panel-card stack-md">
        <div class="panel-head">
            <h2 class="panel-title">คิวงานทั้งหมด</h2>
            <span class="helper-text"><?= e((string) count($filteredTickets)) ?> รายการตรงตามตัวกรอง</span>
        </div>

        <form method="get" action="<?= e(url('/tickets')) ?>" class="filter-bar">
            <div class="filter-search">
                <?= lucide('search', 'h-4 w-4') ?>
                <input type="search" name="q" value="<?= e($qSearch) ?>" placeholder="ค้นหา ticket no, หัวข้อ, ผู้แจ้ง..." aria-label="ค้นหา ticket">
            </div>
            <select name="status" class="input" style="max-width:180px" aria-label="กรองตามสถานะ">
                <option value="">ทุกสถานะ</option>
                <?php foreach (['pending_approval' => 'รออนุมัติ', 'approved' => 'อนุมัติแล้ว', 'assigned' => 'มอบหมายแล้ว', 'in_progress' => 'กำลังดำเนินการ', 'resolved' => 'รอตรวจรับ', 'completed' => 'เสร็จสิ้น', 'rejected' => 'ปฏิเสธ'] as $val => $lab): ?>
                    <option value="<?= e($val) ?>"<?= $qStatus === $val ? ' selected' : '' ?>><?= e($lab) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary btn-md"><?= lucide('filter', 'button-icon') ?><span>กรอง</span></button>
            <?php if ($qSearch !== '' || $qStatus !== '' || $qPriority !== ''): ?>
                <a href="<?= e(url('/tickets')) ?>" class="btn btn-ghost btn-md"><?= lucide('x', 'button-icon') ?><span>ล้าง</span></a>
            <?php endif; ?>
        </form>

        <?php if ($filteredTickets === []): ?>
            <?= render_partial('partials/components/empty-state', [
                'icon' => 'clipboard-list',
                'title' => $qSearch !== '' || $qStatus !== '' ? 'ไม่พบรายการตามเงื่อนไข' : 'ยังไม่มีรายการ',
                'description' => $qSearch !== '' || $qStatus !== '' ? 'ลองล้างตัวกรองหรือเปลี่ยนคำค้น' : 'เมื่อมี ticket ตามสิทธิ์ของคุณ รายการจะปรากฏที่นี่ทันที',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>เลขที่ / หัวข้อ</th>
                        <th>ความสำคัญ</th>
                        <th>สถานะ</th>
                        <th>SLA</th>
                        <th>ผู้แจ้ง</th>
                        <th>สถานที่</th>
                        <th>วันที่แจ้ง</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($filteredTickets as $ticket): ?>
                        <tr class="ticket-row<?= !empty($ticket['is_overdue']) ? ' overdue-row' : '' ?>" data-href="<?= e(url('/tickets/' . $ticket['id'])) ?>">
                            <td>
                                <div class="ticket-row-title">
                                    <code class="mono ticket-no"><?= e($ticket['ticket_no']) ?></code>
                                    <strong><?= e($ticket['title']) ?></strong>
                                    <span class="helper-text"><?= e($ticket['category_name']) ?></span>
                                </div>
                            </td>
                            <td><?= render_partial('partials/components/badge', ['label' => $ticket['priority_label'], 'tone' => $ticket['priority_tone']]) ?></td>
                            <td>
                                <div class="stack-md">
                                    <?= render_partial('partials/components/badge', ['label' => $ticket['status_label'], 'tone' => $ticket['status_tone']]) ?>
                                </div>
                            </td>
                            <td>
                                <div class="stack-md">
                                    <?= render_partial('partials/components/badge', ['label' => $ticket['sla_overview_label'], 'tone' => $ticket['sla_overview_tone']]) ?>
                                    <span class="helper-text">ตอบ: <?= e($ticket['sla_response_label']) ?> · แก้: <?= e($ticket['sla_resolution_label']) ?></span>
                                </div>
                            </td>
                            <td><?= e($ticket['requester_name']) ?></td>
                            <td><?= e($ticket['location_name']) ?></td>
                            <td><?= e(human_date($ticket['requested_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>

<script>
(() => {
    document.querySelectorAll('.ticket-row[data-href]').forEach((row) => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('a,button,input')) return;
            window.location.href = row.dataset.href;
        });
        row.style.cursor = 'pointer';
    });
})();
</script>
