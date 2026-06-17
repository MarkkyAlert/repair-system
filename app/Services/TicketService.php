<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\CommentRepository;
use App\Repositories\TicketRepository;
use App\Core\View;
use DomainException;
use PDO;
use Throwable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;

class TicketService
{
    public function __construct(
        private CommentRepository $comments,
        private TicketRepository $tickets,
        private NotificationService $notifications,
        private AttachmentService $attachments,
        private PDO $db,
    ) {
    }

    public function getDashboardData(array $viewer, array $filters = []): array
    {
        $normalizedFilters = $this->normalizeDashboardFilters($filters);
        $normalizedFilters['preset_user_id'] = (int) ($viewer['id'] ?? 0);
        $normalizedFilters['preset_role'] = (string) ($viewer['role'] ?? 'guest');
        $reference = $this->tickets->getDashboardFilterReferenceData();
        $metrics = $this->tickets->getDashboardMetrics($viewer, $normalizedFilters);
        $recentTickets = array_map(
            fn (array $ticket): array => $this->mapTicketSummary($ticket),
            $this->tickets->getRecentTickets($viewer, $normalizedFilters, 5)
        );
        $year = (int) ($normalizedFilters['year'] ?? (int) date('Y'));
        $monthlyTickets = $this->tickets->getDashboardMonthlyTicketCounts($viewer, $normalizedFilters, $year);
        $categoryBreakdown = $this->tickets->getDashboardCategoryBreakdown($viewer, $normalizedFilters, 6);
        $departmentBreakdown = $this->tickets->getDashboardDepartmentBreakdown($viewer, $normalizedFilters, 6);
        $resolutionTrend = $this->tickets->getDashboardMonthlyResolutionAverages($viewer, $normalizedFilters, $year);
        $topTechnicians = $this->tickets->getDashboardTopTechnicians($viewer, $normalizedFilters, 5);
        $topCategories = $this->tickets->getDashboardTopCategories($viewer, $normalizedFilters, 5);

        return [
            'metrics' => [
                'total' => (int) ($metrics['total_tickets'] ?? 0),
                'pendingApproval' => (int) ($metrics['pending_approval_tickets'] ?? 0),
                'inProgress' => (int) ($metrics['active_work_tickets'] ?? 0),
                'completedThisMonth' => (int) ($metrics['completed_this_month_tickets'] ?? 0),
                'overdue' => (int) ($metrics['overdue_tickets'] ?? 0),
            ],
            'recentTickets' => $recentTickets,
            'filters' => $this->buildDashboardFilterData($normalizedFilters, $reference),
            'charts' => [
                'monthlyTickets' => $this->buildDashboardChart(
                    'Tickets / Month',
                    $this->monthLabels(),
                    $this->buildMonthlySeries($monthlyTickets, 'total_tickets')
                ),
                'categoryBreakdown' => $this->buildDashboardChart(
                    'Tickets by Category',
                    array_map(fn (array $row): string => (string) ($row['category_name'] ?? '-'), $categoryBreakdown),
                    array_map(fn (array $row): int => (int) ($row['total_tickets'] ?? 0), $categoryBreakdown)
                ),
                'departmentBreakdown' => $this->buildDashboardChart(
                    'Tickets by Department',
                    array_map(fn (array $row): string => (string) ($row['department_name'] ?? '-'), $departmentBreakdown),
                    array_map(fn (array $row): int => (int) ($row['total_tickets'] ?? 0), $departmentBreakdown)
                ),
                'resolutionTrend' => $this->buildDashboardChart(
                    'Avg Resolution Hours',
                    $this->monthLabels(),
                    $this->buildMonthlySeries($resolutionTrend, 'avg_minutes', true)
                ),
            ],
            'highlights' => [
                'topTechnicians' => array_map(fn (array $row): array => [
                    'name' => (string) ($row['full_name'] ?? '-'),
                    'ticket_count' => (int) ($row['ticket_count'] ?? 0),
                    'avg_rating' => (float) ($row['avg_rating'] ?? 0),
                    'avg_rating_label' => (float) ($row['avg_rating'] ?? 0) > 0 ? number_format((float) ($row['avg_rating'] ?? 0), 1) : '-',
                    'overdue_count' => (int) ($row['overdue_count'] ?? 0),
                ], $topTechnicians),
                'topCategories' => array_map(fn (array $row): array => [
                    'name' => (string) ($row['category_name'] ?? '-'),
                    'ticket_count' => (int) ($row['total_tickets'] ?? 0),
                    'overdue_count' => (int) ($row['overdue_count'] ?? 0),
                ], $topCategories),
            ],
        ];
    }

    public function getTicketIndexData(array $viewer, array $filters = []): array
    {
        $metrics = $this->getDashboardData($viewer)['metrics'];
        $normalized = [
            'q' => trim((string) ($filters['q'] ?? '')),
            'status' => trim((string) ($filters['status'] ?? '')),
            'priority' => strtoupper(trim((string) ($filters['priority'] ?? ''))),
            'technician_id' => max(0, (int) ($filters['technician_id'] ?? 0)),
            'sla' => trim((string) ($filters['sla'] ?? '')) === 'overdue' ? 'overdue' : '',
        ];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $result = $this->tickets->getVisibleTicketsPage($viewer, $normalized, $page, 20);
        $tickets = array_map(fn (array $ticket): array => $this->mapTicketSummary($ticket), $result['items']);

        return [
            'metrics' => $metrics,
            'tickets' => $tickets,
            'roleLabel' => $this->labelize((string) ($viewer['role'] ?? 'guest')),
            'filters' => $normalized + [
                'technicians' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => (string) $row['full_name'],
                ], $this->tickets->getActiveTechnicians()),
            ],
            'pagination' => $result,
        ];
    }

    public function getCreateFormData(array $viewer, array $oldInput = [], array $prefill = []): array
    {
        $reference = $this->tickets->getCreateFormReferenceData();
        $prefillAsset = null;

        if ($oldInput === []) {
            $prefillAssetId = (int) ($prefill['asset_id'] ?? 0);
            if ($prefillAssetId > 0) {
                $prefillAsset = $this->findReferenceById($reference['assets'] ?? [], $prefillAssetId);
            }
        }

        return [
            'priorities' => array_map(fn (array $priority): array => [
                'id' => (int) $priority['id'],
                'label' => (string) $priority['name'] . ' (' . (string) $priority['code'] . ')',
                'response_minutes' => (int) ($priority['response_time_minutes'] ?? 0),
                'resolution_minutes' => (int) ($priority['resolution_time_minutes'] ?? 0),
            ], $reference['priorities'] ?? []),
            'categories' => array_map(fn (array $category): array => [
                'id' => (int) $category['id'],
                'label' => (string) $category['name'],
            ], $reference['categories'] ?? []),
            'locations' => array_map(fn (array $location): array => [
                'id' => (int) $location['id'],
                'label' => $this->buildReferenceLabel([
                    (string) ($location['name'] ?? ''),
                    (string) ($location['building'] ?? ''),
                    (string) ($location['room'] ?? ''),
                ]),
            ], $reference['locations'] ?? []),
            'assets' => array_map(fn (array $asset): array => [
                'id' => (int) $asset['id'],
                'location_id' => (int) ($asset['location_id'] ?? 0),
                'label' => (string) ($asset['asset_code'] ?? '') . ' - ' . (string) ($asset['name'] ?? ''),
            ], $reference['assets'] ?? []),
            'impactOptions' => $this->enumOptions(['low', 'medium', 'high', 'critical']),
            'urgencyOptions' => $this->enumOptions(['low', 'medium', 'high', 'critical']),
            'prefill' => [
                'has_asset' => $prefillAsset !== null,
                'asset_label' => $prefillAsset !== null ? (string) ($prefillAsset['label'] ?? '') : '',
                'source_ticket_id' => (int) ($prefill['source_ticket_id'] ?? 0),
                'source_ticket_no' => (string) ($prefill['source_ticket_no'] ?? ''),
            ],
            'defaults' => [
                'title' => (string) ($oldInput['title'] ?? ($prefill['title'] ?? '')),
                'description' => (string) ($oldInput['description'] ?? ($prefill['description'] ?? '')),
                'priority_id' => (string) ($oldInput['priority_id'] ?? ($prefill['priority_id'] ?? '')),
                'ticket_category_id' => (string) ($oldInput['ticket_category_id'] ?? ($prefill['ticket_category_id'] ?? '')),
                'location_id' => (string) ($oldInput['location_id'] ?? ($prefillAsset !== null ? (string) ($prefillAsset['location_id'] ?? '') : (string) ($prefill['location_id'] ?? ''))),
                'asset_id' => (string) ($oldInput['asset_id'] ?? ($prefillAsset !== null ? (string) ($prefillAsset['id'] ?? '') : (string) ($prefill['asset_id'] ?? ''))),
                'impact_level' => (string) ($oldInput['impact_level'] ?? ($prefill['impact_level'] ?? 'medium')),
                'urgency_level' => (string) ($oldInput['urgency_level'] ?? ($prefill['urgency_level'] ?? 'medium')),
                'submission_token' => $this->submissionToken((string) ($oldInput['submission_token'] ?? '')),
                'requester_name' => (string) ($viewer['full_name'] ?? ''),
                'requester_email' => (string) ($viewer['email'] ?? ''),
            ],
        ];
    }

    public function createTicket(array $viewer, array $input, array $files = []): int
    {
        $validatedFiles = $this->attachments->validateUploads($files);
        $reference = $this->tickets->getCreateFormReferenceData();

        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $priorityId = (int) ($input['priority_id'] ?? 0);
        $categoryId = (int) ($input['ticket_category_id'] ?? 0);
        $locationId = (int) ($input['location_id'] ?? 0);
        $assetId = (int) ($input['asset_id'] ?? 0);
        $impactLevel = strtolower(trim((string) ($input['impact_level'] ?? 'medium')));
        $urgencyLevel = strtolower(trim((string) ($input['urgency_level'] ?? 'medium')));
        $submissionToken = $this->submissionToken((string) ($input['submission_token'] ?? ''), false);

        if ($title === '' || $description === '') {
            throw new DomainException('กรุณากรอกหัวข้อและรายละเอียดของปัญหาให้ครบถ้วน');
        }

        if ((int) ($viewer['id'] ?? 0) <= 0) {
            throw new DomainException('ไม่พบข้อมูลผู้ใช้งานสำหรับสร้างรายการแจ้งซ่อม');
        }

        $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($titleLength > 200) {
            throw new DomainException('หัวข้อปัญหาต้องไม่เกิน 200 ตัวอักษร');
        }

        if (!in_array($impactLevel, ['low', 'medium', 'high', 'critical'], true) || !in_array($urgencyLevel, ['low', 'medium', 'high', 'critical'], true)) {
            throw new DomainException('ค่าผลกระทบหรือความเร่งด่วนไม่ถูกต้อง');
        }

        $priority = $this->findReferenceById($reference['priorities'] ?? [], $priorityId);
        $category = $this->findReferenceById($reference['categories'] ?? [], $categoryId);
        $location = $this->findReferenceById($reference['locations'] ?? [], $locationId);

        if ($priority === null || $category === null || $location === null) {
            throw new DomainException('กรุณาเลือก Priority, Category และ Location ให้ถูกต้อง');
        }

        $asset = null;
        if ($assetId > 0) {
            $asset = $this->findReferenceById($reference['assets'] ?? [], $assetId);
            if ($asset === null) {
                throw new DomainException('Asset ที่เลือกไม่ถูกต้อง');
            }

            if ((int) ($asset['location_id'] ?? 0) !== $locationId) {
                throw new DomainException('Asset ที่เลือกไม่ได้อยู่ใน Location ที่ระบุ');
            }
        }

        $requestedAt = date('Y-m-d H:i:s');
        $categorySla = setting('category_sla_' . $categoryId, []);
        $responseMinutes = max(0, (int) ($categorySla['response_minutes'] ?? (int) ($priority['response_time_minutes'] ?? 0)));
        $resolutionMinutes = max(0, (int) ($categorySla['resolution_minutes'] ?? (int) ($priority['resolution_time_minutes'] ?? 0)));
        $responseDueAt = date('Y-m-d H:i:s', strtotime($requestedAt . ' +' . $responseMinutes . ' minutes'));
        $resolutionDueAt = date('Y-m-d H:i:s', strtotime($requestedAt . ' +' . $resolutionMinutes . ' minutes'));

        $storedPaths = [];
        $ticketId = 0;
        $created = false;

        try {
            // RISK MAP: Ticket insert + attachment rows/files must stay atomic; keep storedPaths cleanup with any rollback.
            $this->db->beginTransaction();
            $result = $this->tickets->createTicket([
                'submission_token' => $submissionToken,
                'title' => $title,
                'description' => $description,
                'requester_id' => (int) ($viewer['id'] ?? 0),
                'requester_department_id' => isset($viewer['department_id']) ? (int) $viewer['department_id'] : null,
                'location_id' => $locationId,
                'asset_id' => is_array($asset) ? (int) ($asset['id'] ?? 0) : null,
                'ticket_category_id' => $categoryId,
                'priority_id' => $priorityId,
                'impact_level' => $impactLevel,
                'urgency_level' => $urgencyLevel,
                'requested_at' => $requestedAt,
                'response_due_at' => $responseDueAt,
                'resolution_due_at' => $resolutionDueAt,
            ]);

            $ticketId = (int) ($result['id'] ?? 0);
            $created = (bool) ($result['created'] ?? false);

            if ($created) {
                $storedPaths = $this->attachments->storeValidated($validatedFiles, $ticketId, (int) ($viewer['id'] ?? 0));
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->attachments->deleteStoredFiles($storedPaths);
            throw $exception;
        }

        if ($created) {
            try {
                $this->notifications->notifyTicketEvent($ticketId, 'ticket.created', (int) ($viewer['id'] ?? 0));
            } catch (Throwable) {
            }
        }

        return $ticketId;
    }

    public function getDuplicateFormData(int $ticketId, array $viewer): array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null || !$this->canDuplicateTicket($ticket, $viewer)) {
            throw new DomainException('Ticket นี้ไม่สามารถใช้เปิดรายการใหม่ได้');
        }

        return $this->getCreateFormData($viewer, [], [
            'source_ticket_id' => $ticketId,
            'source_ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
            'title' => (string) ($ticket['title'] ?? ''),
            'description' => (string) ($ticket['description'] ?? ''),
            'priority_id' => (string) ($ticket['priority_id'] ?? ''),
            'ticket_category_id' => (string) ($ticket['ticket_category_id'] ?? ''),
            'location_id' => (string) ($ticket['location_id'] ?? ''),
            'asset_id' => (string) ($ticket['asset_id'] ?? ''),
            'impact_level' => (string) ($ticket['impact_level'] ?? 'medium'),
            'urgency_level' => (string) ($ticket['urgency_level'] ?? 'medium'),
        ]);
    }

    public function getTicketDetailData(int $ticketId, array $viewer, array $oldInput = []): ?array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $includeInternal = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        $allAttachments = $this->attachments->getTicketAttachments($ticketId, $includeInternal);
        $commentAttachments = [];
        foreach ($allAttachments as $attachment) {
            $commentAttachments[(int) ($attachment['comment_id'] ?? 0)][] = $attachment;
        }

        return [
            'ticket' => $this->mapTicketDetail($ticket),
            'attachments' => $commentAttachments[0] ?? [],
            'comments' => array_map(function (array $comment) use ($viewer, $commentAttachments): array {
                $mapped = $this->mapComment($comment, $viewer);
                $mapped['attachments'] = $commentAttachments[(int) ($comment['id'] ?? 0)] ?? [];
                return $mapped;
            }, $this->comments->getCommentsByTicketId($ticketId, $includeInternal)),
            'activityLogs' => array_map(fn (array $log): array => $this->mapActivityLog($log), $this->tickets->getActivityLogsByTicketId($ticketId)),
            'workflow' => $this->buildWorkflowData($ticket, $viewer, $oldInput),
        ];
    }

    public function getPrintableTicketData(int $ticketId, array $viewer, string $paper = 'a4'): ?array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $mapped = $this->mapTicketDetail($ticket);
        $paper = $this->normalizePaperSize($paper);

        return [
            'paper' => $paper,
            'paper_label' => strtoupper($paper),
            'printed_at' => date('d/m/Y H:i'),
            'ticket' => $mapped + [
                'ticket_url' => url('/tickets/' . (int) ($mapped['id'] ?? $ticketId)),
                'print_qr_url' => url('/tickets/' . (int) ($mapped['id'] ?? $ticketId) . '/print/qr.png'),
            ],
        ];
    }

    public function generatePrintableTicketPdf(int $ticketId, array $viewer, string $paper = 'a4'): array
    {
        $print = $this->getPrintableTicketData($ticketId, $viewer, $paper);
        if ($print === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดาวน์โหลดเป็น PDF');
        }

        $html = View::capture('tickets/pdf', [
            'ticket' => $print['ticket'],
            'paperLabel' => $print['paper_label'],
            'printedAt' => $print['printed_at'],
        ]);

        $options = new Options();
        $options->setTempDir('/private/tmp');
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper(strtolower((string) ($print['paper_label'] ?? 'A4')) === 'a5' ? 'A5' : 'A4');
        $dompdf->render();

        return [
            'content' => $dompdf->output(),
            'file_name' => 'job-order-' . (string) ($print['ticket']['ticket_no'] ?? $ticketId) . '.pdf',
            'content_type' => 'application/pdf',
        ];
    }

    public function generatePrintQrPng(int $ticketId, array $viewer): string
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบ ticket ที่ต้องการสร้าง QR สำหรับพิมพ์');
        }

        $result = Builder::create()
            ->writer(new PngWriter())
            ->data(url('/tickets/' . $ticketId))
            ->encoding(new Encoding('UTF-8'))
            ->size(300)
            ->margin(12)
            ->build();

        return $result->getString();
    }

    public function approveTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่อนุมัติได้');
        }

        $note = trim((string) ($input['note'] ?? ''));
        $this->tickets->approveTicket($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'pending_approval'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.approved', (int) ($viewer['id'] ?? 0));
    }

    public function rejectTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->canReviewTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่ปฏิเสธได้');
        }

        $note = trim((string) ($input['note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลในการปฏิเสธรายการนี้');
        }

        $this->tickets->rejectTicket($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'pending_approval'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.rejected', (int) ($viewer['id'] ?? 0));
    }

    public function assignTechnician(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireManageableTicket($ticketId, $viewer);

        if (!$this->canAssignTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการมอบหมายช่าง');
        }

        $technicianId = (int) ($input['technician_id'] ?? 0);
        if ($technicianId <= 0) {
            throw new DomainException('กรุณาเลือกช่างเทคนิคที่ต้องการมอบหมาย');
        }

        $technician = $this->findReferenceById($this->tickets->getActiveTechnicians(), $technicianId);
        if ($technician === null) {
            throw new DomainException('ไม่พบช่างเทคนิคที่เลือก หรือช่างไม่พร้อมใช้งาน');
        }

        $instructions = trim((string) ($input['instructions'] ?? ''));

        $this->tickets->assignTechnician(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $technicianId,
            (string) ($technician['full_name'] ?? 'Technician'),
            $instructions,
            (string) ($ticket['status'] ?? 'approved')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.assigned', (int) ($viewer['id'] ?? 0));
    }

    public function acceptAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->canAcceptTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการรับงาน');
        }

        $note = trim((string) ($input['accept_note'] ?? ''));
        $this->tickets->acceptAssignedWork($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'assigned'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.accepted', (int) ($viewer['id'] ?? 0));
    }

    public function startAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->canStartTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการเริ่มงาน');
        }

        $note = trim((string) ($input['start_note'] ?? ''));
        $this->tickets->startAssignedWork($ticketId, (int) ($viewer['id'] ?? 0), $note, (string) ($ticket['status'] ?? 'assigned'));
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.started', (int) ($viewer['id'] ?? 0));
    }

    public function resolveAssignedWork(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireTechnicianTicket($ticketId, $viewer);

        if (!$this->canResolveTechnicianWork($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการสรุปผลการซ่อม');
        }

        $diagnosisSummary = trim((string) ($input['diagnosis_summary'] ?? ''));
        $resolutionSummary = trim((string) ($input['resolution_summary'] ?? ''));
        $laborMinutes = (int) ($input['labor_minutes'] ?? 0);

        if ($diagnosisSummary === '' || $resolutionSummary === '') {
            throw new DomainException('กรุณากรอกผลการวิเคราะห์และวิธีแก้ไขให้ครบถ้วน');
        }

        if ($laborMinutes < 0) {
            throw new DomainException('จำนวนเวลาที่ใช้ต้องเป็นตัวเลขศูนย์หรือมากกว่า');
        }

        $this->tickets->resolveAssignedWork(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $diagnosisSummary,
            $resolutionSummary,
            $laborMinutes,
            (string) ($ticket['status'] ?? 'in_progress')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.resolved', (int) ($viewer['id'] ?? 0));
    }

    public function completeResolvedTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);

        if (!$this->canRequesterCompleteTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการยืนยันปิดงาน');
        }

        $closureNote = trim((string) ($input['closure_note'] ?? ''));
        $score = (int) ($input['score'] ?? 0);
        $feedback = trim((string) ($input['feedback'] ?? ''));

        if ($score < 1 || $score > 5) {
            throw new DomainException('กรุณาให้คะแนนความพึงพอใจตั้งแต่ 1 ถึง 5');
        }

        $this->tickets->completeResolvedTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            isset($ticket['assigned_technician_id']) ? (int) $ticket['assigned_technician_id'] : null,
            $closureNote,
            $score,
            $feedback,
            (string) ($ticket['status'] ?? 'resolved')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.completed', (int) ($viewer['id'] ?? 0));
    }

    public function reopenTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);
        if (!$this->canRequesterReopenTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ยังไม่พร้อมสำหรับการส่งกลับไปแก้งานซ้ำ');
        }

        $note = trim((string) ($input['reopen_note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลที่ต้องการให้ดำเนินการซ้ำ');
        }

        $reopenedAt = date('Y-m-d H:i:s');
        $responseDueAt = $this->calculateReopenDueAt($ticket, 'response_due_at', $reopenedAt);
        $resolutionDueAt = $this->calculateReopenDueAt($ticket, 'resolution_due_at', $reopenedAt);

        $this->tickets->reopenTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $note,
            (string) ($ticket['status'] ?? ''),
            $responseDueAt,
            $resolutionDueAt
        );

        try {
            $this->notifications->notifyTicketEvent($ticketId, 'ticket.reopened', (int) ($viewer['id'] ?? 0));
        } catch (Throwable) {
        }
    }

    public function cancelTicket(int $ticketId, array $viewer, array $input): void
    {
        $ticket = $this->requireRequesterTicket($ticketId, $viewer);
        if (!$this->canRequesterCancelTicket($ticket, $viewer)) {
            throw new DomainException('รายการนี้ไม่อยู่ในสถานะที่ยกเลิกได้');
        }

        $note = trim((string) ($input['cancel_note'] ?? ''));
        if ($note === '') {
            throw new DomainException('กรุณาระบุเหตุผลในการยกเลิก Ticket');
        }

        $this->tickets->cancelTicket(
            $ticketId,
            (int) ($viewer['id'] ?? 0),
            $note,
            (string) ($ticket['status'] ?? '')
        );
        $this->notifications->notifyTicketEvent($ticketId, 'ticket.cancelled', (int) ($viewer['id'] ?? 0));
    }

    private function mapTicketSummary(array $ticket): array
    {
        $priorityCode = strtoupper((string) ($ticket['priority_code'] ?? 'MEDIUM'));
        $status = (string) ($ticket['status'] ?? 'submitted');
        $approvalStatus = (string) ($ticket['approval_status'] ?? 'pending');
        $sla = $this->buildSlaSummary($ticket);

        return [
            'id' => (int) ($ticket['id'] ?? 0),
            'ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
            'title' => (string) ($ticket['title'] ?? ''),
            'status' => $status,
            'status_label' => $this->labelize($status),
            'status_tone' => $this->statusTone($status),
            'approval_status' => $approvalStatus,
            'approval_label' => $this->labelize($approvalStatus),
            'approval_tone' => $this->approvalTone($approvalStatus),
            'priority_code' => $priorityCode,
            'priority_label' => (string) ($ticket['priority_name'] ?? $priorityCode),
            'priority_tone' => $this->priorityTone($priorityCode),
            'location_name' => (string) ($ticket['location_name'] ?? '-'),
            'requester_name' => (string) ($ticket['requester_name'] ?? '-'),
            'technician_name' => (string) ($ticket['technician_name'] ?? '-'),
            'category_name' => (string) ($ticket['category_name'] ?? '-'),
            'channel_label' => $this->labelize((string) ($ticket['channel'] ?? 'web')),
            'requested_at' => $this->formatDateTime($ticket['requested_at'] ?? null),
            'updated_at' => $this->formatDateTime($ticket['updated_at'] ?? null),
            'response_due_at' => $this->formatDateTime($ticket['response_due_at'] ?? null),
            'resolution_due_at' => $this->formatDateTime($ticket['resolution_due_at'] ?? null),
            'sla_overview_label' => (string) ($sla['label'] ?? '-'),
            'sla_overview_tone' => (string) ($sla['tone'] ?? 'default'),
            'sla_response_label' => (string) (($sla['response']['label'] ?? '-')),
            'sla_resolution_label' => (string) (($sla['resolution']['label'] ?? '-')),
            'is_overdue' => (bool) ($sla['is_overdue'] ?? false),
        ];
    }

    private function mapTicketDetail(array $ticket): array
    {
        $mapped = $this->mapTicketSummary($ticket);
        $sla = $this->buildSlaSummary($ticket);

        return $mapped + [
            'description' => (string) ($ticket['description'] ?? ''),
            'assigned_manager_id' => (int) ($ticket['assigned_manager_id'] ?? 0),
            'assigned_technician_id' => (int) ($ticket['assigned_technician_id'] ?? 0),
            'work_order_no' => (string) ($ticket['work_order_no'] ?? ''),
            'work_order_status' => (string) ($ticket['work_order_status'] ?? ''),
            'work_order_status_label' => $this->labelize((string) ($ticket['work_order_status'] ?? '')),
            'work_order_instructions' => (string) ($ticket['work_order_instructions'] ?? ''),
            'work_order_diagnosis_summary' => (string) ($ticket['work_order_diagnosis_summary'] ?? ''),
            'work_order_resolution_summary' => (string) ($ticket['work_order_resolution_summary'] ?? ''),
            'work_order_labor_minutes' => (int) ($ticket['work_order_labor_minutes'] ?? 0),
            'impact_level' => $this->labelize((string) ($ticket['impact_level'] ?? 'medium')),
            'urgency_level' => $this->labelize((string) ($ticket['urgency_level'] ?? 'medium')),
            'requester_email' => (string) ($ticket['requester_email'] ?? '-'),
            'requester_phone' => (string) ($ticket['requester_phone'] ?? '-'),
            'manager_name' => (string) ($ticket['manager_name'] ?? '-'),
            'asset_code' => (string) ($ticket['asset_code'] ?? '-'),
            'asset_name' => (string) ($ticket['asset_name'] ?? '-'),
            'location_detail' => $this->buildLocationDetail($ticket),
            'approved_at' => $this->formatDateTime($ticket['approved_at'] ?? null),
            'assigned_at' => $this->formatDateTime($ticket['assigned_at'] ?? null),
            'started_at' => $this->formatDateTime($ticket['started_at'] ?? null),
            'first_response_at' => $this->formatDateTime($ticket['first_response_at'] ?? null),
            'resolved_at' => $this->formatDateTime($ticket['resolved_at'] ?? null),
            'completed_at' => $this->formatDateTime($ticket['completed_at'] ?? null),
            'cancelled_at' => $this->formatDateTime($ticket['cancelled_at'] ?? null),
            'closed_at' => $this->formatDateTime($ticket['closed_at'] ?? null),
            'response_due_at' => $this->formatDateTime($ticket['response_due_at'] ?? null),
            'work_order_assigned_at' => $this->formatDateTime($ticket['work_order_assigned_at'] ?? null),
            'work_order_accepted_at' => $this->formatDateTime($ticket['work_order_accepted_at'] ?? null),
            'work_order_started_at' => $this->formatDateTime($ticket['work_order_started_at'] ?? null),
            'work_order_completed_at' => $this->formatDateTime($ticket['work_order_completed_at'] ?? null),
            'resolution_summary' => (string) ($ticket['resolution_summary'] ?? ''),
            'closure_note' => (string) ($ticket['closure_note'] ?? ''),
            'rating_score' => (int) ($ticket['rating_score'] ?? 0),
            'rating_feedback' => (string) ($ticket['rating_feedback'] ?? ''),
            'rating_created_at' => $this->formatDateTime($ticket['rating_created_at'] ?? null),
            'sla_overview' => $sla,
            'sla_response' => $sla['response'],
            'sla_resolution' => $sla['resolution'],
            'print_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print',
            'print_a5_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print?paper=a5',
            'print_pdf_url' => '/tickets/' . (int) ($ticket['id'] ?? 0) . '/print/pdf',
        ];
    }

    private function buildWorkflowData(array $ticket, array $viewer, array $oldInput): array
    {
        $managerCanAct = $this->canManageWorkflow($ticket, $viewer);
        $canReview = $managerCanAct && $this->canReviewTicket($ticket, $viewer);
        $canAssign = $managerCanAct && $this->canAssignTicket($ticket, $viewer);
        $technicianCanAct = $this->canTechnicianWork($ticket, $viewer);
        $canAccept = $technicianCanAct && $this->canAcceptTechnicianWork($ticket, $viewer);
        $canStart = $technicianCanAct && $this->canStartTechnicianWork($ticket, $viewer);
        $canResolve = $technicianCanAct && $this->canResolveTechnicianWork($ticket, $viewer);
        $requesterCanAct = $this->canRequesterManageClosure($ticket, $viewer);
        $canComplete = $requesterCanAct && $this->canRequesterCompleteTicket($ticket, $viewer);
        $canReopen = $requesterCanAct && $this->canRequesterReopenTicket($ticket, $viewer);
        $canCancel = $requesterCanAct && $this->canRequesterCancelTicket($ticket, $viewer);
        $canDuplicate = $this->canDuplicateTicket($ticket, $viewer);
        $canComment = (int) ($viewer['id'] ?? 0) > 0;
        $canUseInternalComment = (string) ($viewer['role'] ?? 'guest') !== 'requester';

        return [
            'managerCanAct' => $managerCanAct,
            'canReview' => $canReview,
            'canAssign' => $canAssign,
            'technicianCanAct' => $technicianCanAct,
            'canAccept' => $canAccept,
            'canStart' => $canStart,
            'canResolve' => $canResolve,
            'requesterCanAct' => $requesterCanAct,
            'canComplete' => $canComplete,
            'canReopen' => $canReopen,
            'canCancel' => $canCancel,
            'canDuplicate' => $canDuplicate,
            'canComment' => $canComment,
            'canUseInternalComment' => $canUseInternalComment,
            'technicians' => array_map(fn (array $technician): array => [
                'id' => (int) ($technician['id'] ?? 0),
                'label' => (string) ($technician['full_name'] ?? '-'),
            ], $this->tickets->getActiveTechnicians()),
            'workOrder' => [
                'number' => (string) ($ticket['work_order_no'] ?? ''),
                'status' => $this->labelize((string) ($ticket['work_order_status'] ?? '')),
                'instructions' => (string) ($ticket['work_order_instructions'] ?? ''),
                'diagnosis_summary' => (string) ($ticket['work_order_diagnosis_summary'] ?? ''),
                'resolution_summary' => (string) ($ticket['work_order_resolution_summary'] ?? ''),
                'labor_minutes' => (int) ($ticket['work_order_labor_minutes'] ?? 0),
                'assigned_at' => $this->formatDateTime($ticket['work_order_assigned_at'] ?? null),
                'accepted_at' => $this->formatDateTime($ticket['work_order_accepted_at'] ?? null),
                'started_at' => $this->formatDateTime($ticket['work_order_started_at'] ?? null),
                'completed_at' => $this->formatDateTime($ticket['work_order_completed_at'] ?? null),
            ],
            'rating' => [
                'score' => (int) ($ticket['rating_score'] ?? 0),
                'feedback' => (string) ($ticket['rating_feedback'] ?? ''),
                'created_at' => $this->formatDateTime($ticket['rating_created_at'] ?? null),
            ],
            'defaults' => [
                'note' => (string) ($oldInput['note'] ?? ''),
                'technician_id' => (string) ($oldInput['technician_id'] ?? (string) ($ticket['assigned_technician_id'] ?? '')),
                'instructions' => (string) ($oldInput['instructions'] ?? ''),
                'accept_note' => (string) ($oldInput['accept_note'] ?? ''),
                'start_note' => (string) ($oldInput['start_note'] ?? ''),
                'diagnosis_summary' => (string) ($oldInput['diagnosis_summary'] ?? (string) ($ticket['work_order_diagnosis_summary'] ?? '')),
                'resolution_summary' => (string) ($oldInput['resolution_summary'] ?? (string) ($ticket['work_order_resolution_summary'] ?? '')),
                'labor_minutes' => (string) ($oldInput['labor_minutes'] ?? (string) ((int) ($ticket['work_order_labor_minutes'] ?? 0))),
                'closure_note' => (string) ($oldInput['closure_note'] ?? (string) ($ticket['closure_note'] ?? '')),
                'reopen_note' => (string) ($oldInput['reopen_note'] ?? ''),
                'cancel_note' => (string) ($oldInput['cancel_note'] ?? ''),
                'score' => (string) ($oldInput['score'] ?? (string) ((int) ($ticket['rating_score'] ?? 0))),
                'feedback' => (string) ($oldInput['feedback'] ?? (string) ($ticket['rating_feedback'] ?? '')),
                'comment_body' => (string) ($oldInput['comment_body'] ?? ''),
                'has_comment_body_old_input' => array_key_exists('comment_body', $oldInput),
                'comment_is_internal' => (string) ($oldInput['comment_is_internal'] ?? ''),
                'comment_submission_token' => $this->submissionToken((string) ($oldInput['comment_submission_token'] ?? '')),
                'has_comment_is_internal_old_input' => array_key_exists('comment_is_internal', $oldInput),
                'editing_comment_id' => (string) ($oldInput['editing_comment_id'] ?? ''),
            ],
        ];
    }

    private function mapComment(array $comment, array $viewer): array
    {
        $isInternal = (bool) ($comment['is_internal'] ?? false);

        return [
            'id' => (int) ($comment['id'] ?? 0),
            'user_id' => (int) ($comment['user_id'] ?? 0),
            'is_internal' => $isInternal,
            'author_name' => (string) ($comment['author_name'] ?? 'Unknown'),
            'author_role' => $this->labelize((string) ($comment['author_role'] ?? 'user')),
            'body' => (string) ($comment['body'] ?? ''),
            'visibility_label' => $isInternal ? 'Internal' : 'Public',
            'visibility_tone' => $isInternal ? 'warning' : 'default',
            'created_at' => $this->formatDateTime($comment['created_at'] ?? null),
            'can_manage' => $this->canManageComment($comment, $viewer),
        ];
    }

    private function mapActivityLog(array $log): array
    {
        $action = (string) ($log['action'] ?? 'updated');

        return [
            'actor_name' => (string) ($log['actor_name'] ?? 'System'),
            'actor_role' => $this->labelize((string) ($log['actor_role'] ?? 'system')),
            'action_label' => $this->activityActionLabel($action),
            'action_tone' => $this->activityActionTone($action),
            'details' => (string) ($log['details'] ?? ''),
            'from_status' => $this->labelize((string) ($log['from_status'] ?? '')),
            'to_status' => $this->labelize((string) ($log['to_status'] ?? '')),
            'created_at' => $this->formatDateTime($log['created_at'] ?? null),
        ];
    }

    private function activityActionLabel(string $action): string
    {
        return match ($action) {
            'ticket_reopened' => 'ขอแก้งานซ้ำ',
            default => $this->labelize($action),
        };
    }

    private function activityActionTone(string $action): string
    {
        return match ($action) {
            'ticket_reopened' => 'warning',
            default => 'default',
        };
    }

    private function formatDateTime(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '-';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return (string) $value;
        }

        return date('d/m/Y H:i', $timestamp);
    }

    private function buildSlaSummary(array $ticket): array
    {
        if ((string) ($ticket['status'] ?? '') === 'cancelled') {
            $notApplicable = [
                'status' => 'unavailable',
                'label' => 'Not applicable',
                'tone' => 'default',
                'target_at' => '-',
                'achieved_at' => '-',
            ];

            return [
                'label' => 'SLA not applicable',
                'tone' => 'default',
                'is_overdue' => false,
                'response' => ['name' => 'Response SLA'] + $notApplicable,
                'resolution' => ['name' => 'Resolution SLA'] + $notApplicable,
            ];
        }

        $response = $this->buildSlaMetricState(
            'Response SLA',
            $ticket['response_due_at'] ?? null,
            $ticket['first_response_at'] ?? null
        );
        $resolution = $this->buildSlaMetricState(
            'Resolution SLA',
            $ticket['resolution_due_at'] ?? null,
            $ticket['resolved_at'] ?? null
        );

        $isOverdue = ($response['status'] ?? '') === 'breached' || ($resolution['status'] ?? '') === 'breached';

        if (($resolution['status'] ?? '') === 'breached') {
            $label = 'Resolution overdue';
            $tone = 'danger';
        } elseif (($response['status'] ?? '') === 'breached') {
            $label = 'Response overdue';
            $tone = 'danger';
        } elseif (($response['status'] ?? '') === 'met' && ($resolution['status'] ?? '') === 'met') {
            $label = 'Within SLA';
            $tone = 'success';
        } elseif (($response['status'] ?? '') === 'pending') {
            $label = 'Response pending';
            $tone = 'warning';
        } elseif (($resolution['status'] ?? '') === 'pending') {
            $label = 'Resolution pending';
            $tone = 'info';
        } else {
            $label = 'SLA tracked';
            $tone = 'default';
        }

        return [
            'label' => $label,
            'tone' => $tone,
            'is_overdue' => $isOverdue,
            'response' => $response,
            'resolution' => $resolution,
        ];
    }

    private function buildSlaMetricState(string $name, mixed $targetAt, mixed $achievedAt): array
    {
        $target = is_string($targetAt) ? trim($targetAt) : '';
        $achieved = is_string($achievedAt) ? trim($achievedAt) : '';
        $targetTimestamp = $target !== '' ? strtotime($target) : false;
        $achievedTimestamp = $achieved !== '' ? strtotime($achieved) : false;

        if ($targetTimestamp === false) {
            return [
                'name' => $name,
                'status' => 'unavailable',
                'label' => 'Not set',
                'tone' => 'default',
                'target_at' => '-',
                'achieved_at' => $this->formatDateTime($achievedAt),
            ];
        }

        if ($achievedTimestamp !== false) {
            $isBreached = $achievedTimestamp > $targetTimestamp;

            return [
                'name' => $name,
                'status' => $isBreached ? 'breached' : 'met',
                'label' => $isBreached ? 'Breached' : 'Met',
                'tone' => $isBreached ? 'danger' : 'success',
                'target_at' => $this->formatDateTime($targetAt),
                'achieved_at' => $this->formatDateTime($achievedAt),
            ];
        }

        $isBreached = $targetTimestamp < time();

        return [
            'name' => $name,
            'status' => $isBreached ? 'breached' : 'pending',
            'label' => $isBreached ? 'Breached' : 'Pending',
            'tone' => $isBreached ? 'danger' : 'warning',
            'target_at' => $this->formatDateTime($targetAt),
            'achieved_at' => '-',
        ];
    }

    private function normalizePaperSize(string $paper): string
    {
        $paper = strtolower(trim($paper));

        return in_array($paper, ['a4', 'a5'], true) ? $paper : 'a4';
    }

    private function buildLocationDetail(array $ticket): string
    {
        $parts = array_filter([
            (string) ($ticket['location_name'] ?? ''),
            (string) ($ticket['building'] ?? ''),
            (string) ($ticket['floor'] ?? ''),
            (string) ($ticket['room'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '');

        return $parts !== [] ? implode(' / ', $parts) : '-';
    }

    private function buildReferenceLabel(array $parts): string
    {
        $segments = array_values(array_filter($parts, static fn (string $value): bool => trim($value) !== ''));

        return $segments !== [] ? implode(' / ', $segments) : '-';
    }

    private function normalizeDashboardFilters(array $filters): array
    {
        $fromDate = is_string($filters['from_date'] ?? null) ? trim((string) $filters['from_date']) : '';
        $toDate = is_string($filters['to_date'] ?? null) ? trim((string) $filters['to_date']) : '';
        $status = is_string($filters['status'] ?? null) ? trim((string) $filters['status']) : '';
        $preset = is_string($filters['preset'] ?? null) ? trim((string) $filters['preset']) : '';
        $year = (int) ($filters['year'] ?? date('Y'));

        $allowedStatuses = [
            'submitted',
            'pending_approval',
            'approved',
            'assigned',
            'accepted',
            'in_progress',
            'on_hold',
            'resolved',
            'completed',
            'rejected',
            'cancelled',
            'closed',
        ];

        $currentYear = (int) date('Y');
        if ($year < 2020 || $year > ($currentYear + 1)) {
            $year = $currentYear;
        }

        $normalized = [
            'from_date' => $this->normalizeDateInput($fromDate),
            'to_date' => $this->normalizeDateInput($toDate),
            'from_datetime' => '',
            'to_datetime' => '',
            'department_id' => max(0, (int) ($filters['department_id'] ?? 0)),
            'category_id' => max(0, (int) ($filters['category_id'] ?? 0)),
            'status' => in_array($status, $allowedStatuses, true) ? $status : '',
            'year' => $year,
            'preset' => in_array($preset, ['mine', 'overdue', 'pending_approval', 'today'], true) ? $preset : '',
        ];

        if ($normalized['from_date'] !== '') {
            $normalized['from_datetime'] = $normalized['from_date'] . ' 00:00:00';
        }

        if ($normalized['to_date'] !== '') {
            $normalized['to_datetime'] = $normalized['to_date'] . ' 23:59:59';
        }

        if ($normalized['from_date'] !== '' && $normalized['to_date'] !== '' && strcmp($normalized['from_date'], $normalized['to_date']) > 0) {
            [$normalized['from_date'], $normalized['to_date']] = [$normalized['to_date'], $normalized['from_date']];
            [$normalized['from_datetime'], $normalized['to_datetime']] = [$normalized['to_date'] . ' 00:00:00', $normalized['from_date'] . ' 23:59:59'];
        }

        return $normalized;
    }

    private function buildDashboardFilterData(array $filters, array $reference): array
    {
        $yearRows = $reference['years'] ?? [];
        $years = array_map(
            fn (array $row): int => (int) ($row['report_year'] ?? 0),
            array_filter($yearRows, static fn (array $row): bool => (int) ($row['report_year'] ?? 0) > 0)
        );

        if ($years === []) {
            $years = [(int) date('Y')];
        }

        return [
            'selected' => [
                'from_date' => (string) ($filters['from_date'] ?? ''),
                'to_date' => (string) ($filters['to_date'] ?? ''),
                'department_id' => (string) ($filters['department_id'] ?? ''),
                'category_id' => (string) ($filters['category_id'] ?? ''),
                'status' => (string) ($filters['status'] ?? ''),
                'year' => (string) ($filters['year'] ?? date('Y')),
                'preset' => (string) ($filters['preset'] ?? ''),
            ],
            'departmentOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกแผนก'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['departments'] ?? [])),
            'categoryOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกหมวด'],
            ], array_map(fn (array $row): array => [
                'value' => (string) ($row['id'] ?? 0),
                'label' => (string) ($row['name'] ?? '-'),
            ], $reference['categories'] ?? [])),
            'statusOptions' => array_merge([
                ['value' => '', 'label' => 'ทุกสถานะ'],
            ], $this->enumOptions([
                'submitted',
                'pending_approval',
                'approved',
                'assigned',
                'accepted',
                'in_progress',
                'on_hold',
                'resolved',
                'completed',
                'rejected',
                'cancelled',
                'closed',
            ])),
            'yearOptions' => array_map(fn (int $year): array => [
                'value' => (string) $year,
                'label' => (string) $year,
            ], $years),
            'active_count' => $this->countActiveDashboardFilters($filters),
        ];
    }

    private function countActiveDashboardFilters(array $filters): int
    {
        $count = 0;

        foreach (['from_date', 'to_date', 'department_id', 'category_id', 'status', 'preset'] as $key) {
            $value = $filters[$key] ?? '';
            if (is_string($value) && trim($value) !== '') {
                $count++;
                continue;
            }

            if (is_int($value) && $value > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function normalizeDateInput(string $value): string
    {
        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '' : date('Y-m-d', $timestamp);
    }

    private function monthLabels(): array
    {
        return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    }

    private function buildMonthlySeries(array $rows, string $valueKey, bool $minutesToHours = false): array
    {
        $series = array_fill(0, 12, 0);

        foreach ($rows as $row) {
            $monthIndex = max(1, min(12, (int) ($row['month_no'] ?? 0))) - 1;
            $value = (float) ($row[$valueKey] ?? 0);
            $series[$monthIndex] = $minutesToHours ? round($value / 60, 1) : (int) round($value);
        }

        return $series;
    }

    private function buildDashboardChart(string $label, array $labels, array $data): array
    {
        return [
            'label' => $label,
            'labels' => array_values($labels),
            'data' => array_values($data),
            'has_data' => array_sum(array_map(static fn (mixed $value): float => (float) $value, $data)) > 0,
        ];
    }

    private function enumOptions(array $values): array
    {
        return array_map(fn (string $value): array => [
            'value' => $value,
            'label' => $this->labelize($value),
        ], $values);
    }

    private function findReferenceById(array $items, int $id): ?array
    {
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return $item;
            }
        }

        return null;
    }

    private function requireManageableTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->canManageWorkflow($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการ workflow ของรายการนี้');
        }

        return $ticket;
    }

    private function requireTechnicianTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->canTechnicianWork($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์จัดการงานช่างของรายการนี้');
        }

        return $ticket;
    }

    private function requireRequesterTicket(int $ticketId, array $viewer): array
    {
        $ticket = $this->tickets->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            throw new DomainException('ไม่พบรายการแจ้งซ่อมที่ต้องการดำเนินการ');
        }

        if (!$this->canRequesterManageClosure($ticket, $viewer)) {
            throw new DomainException('คุณไม่มีสิทธิ์ยืนยันผลการซ่อมของรายการนี้');
        }

        return $ticket;
    }

    private function canManageWorkflow(array $ticket, array $viewer): bool
    {
        $role = (string) ($viewer['role'] ?? 'guest');
        $viewerId = (int) ($viewer['id'] ?? 0);
        $managerId = (int) ($ticket['assigned_manager_id'] ?? 0);

        if ($role === 'admin') {
            return true;
        }

        return $role === 'manager' && $viewerId > 0 && ($managerId === 0 || $managerId === $viewerId);
    }

    private function canReviewTicket(array $ticket, array $viewer): bool
    {
        return $this->canManageWorkflow($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'pending'
            && (string) ($ticket['status'] ?? '') === 'pending_approval';
    }

    private function canAssignTicket(array $ticket, array $viewer): bool
    {
        if (!$this->canManageWorkflow($ticket, $viewer)) {
            return false;
        }

        return (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['approved', 'assigned'], true);
    }

    private function canTechnicianWork(array $ticket, array $viewer): bool
    {
        return (string) ($viewer['role'] ?? 'guest') === 'technician'
            && (int) ($viewer['id'] ?? 0) > 0
            && (int) ($ticket['assigned_technician_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
    }

    private function canAcceptTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && (string) ($ticket['status'] ?? '') === 'assigned';
    }

    private function canStartTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['assigned', 'accepted'], true);
    }

    private function canResolveTechnicianWork(array $ticket, array $viewer): bool
    {
        return $this->canTechnicianWork($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['accepted', 'in_progress'], true);
    }

    private function canRequesterManageClosure(array $ticket, array $viewer): bool
    {
        return (int) ($viewer['id'] ?? 0) > 0
            && (int) ($ticket['requester_id'] ?? 0) === (int) ($viewer['id'] ?? 0);
    }

    private function canRequesterCompleteTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && (string) ($ticket['status'] ?? '') === 'resolved';
    }

    private function canRequesterReopenTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && (string) ($ticket['approval_status'] ?? '') === 'approved'
            && in_array((string) ($ticket['status'] ?? ''), ['resolved', 'completed'], true);
    }

    private function canRequesterCancelTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && in_array((string) ($ticket['status'] ?? ''), ['pending_approval', 'approved'], true);
    }

    private function canDuplicateTicket(array $ticket, array $viewer): bool
    {
        return $this->canRequesterManageClosure($ticket, $viewer)
            && in_array((string) ($ticket['status'] ?? ''), ['completed', 'rejected', 'cancelled', 'closed'], true);
    }

    private function canManageComment(array $comment, array $viewer): bool
    {
        $viewerId = (int) ($viewer['id'] ?? 0);
        $role = (string) ($viewer['role'] ?? 'guest');

        return $viewerId > 0 && (
            (int) ($comment['user_id'] ?? 0) === $viewerId
            || in_array($role, ['manager', 'admin'], true)
        );
    }

    private function labelize(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '-';
        }

        return ucwords(str_replace('_', ' ', $normalized));
    }

    private function calculateReopenDueAt(array $ticket, string $dueField, string $reopenedAt): string
    {
        $requestedAt = strtotime((string) ($ticket['requested_at'] ?? ''));
        $currentDueAt = strtotime((string) ($ticket[$dueField] ?? ''));
        $reopenedTimestamp = strtotime($reopenedAt) ?: time();

        if ($requestedAt === false || $currentDueAt === false || $currentDueAt < $requestedAt) {
            return date('Y-m-d H:i:s', $reopenedTimestamp);
        }

        $minutes = max(0, (int) ceil(($currentDueAt - $requestedAt) / 60));

        return date('Y-m-d H:i:s', strtotime('+' . $minutes . ' minutes', $reopenedTimestamp) ?: $reopenedTimestamp);
    }

    private function submissionToken(string $token, bool $generateWhenMissing = true): string
    {
        $token = strtolower(trim($token));
        if (preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
            return $token;
        }

        if (!$generateWhenMissing) {
            throw new DomainException('แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้าแล้วลองอีกครั้ง');
        }

        return bin2hex(random_bytes(32));
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            'resolved', 'completed' => 'success',
            'pending_approval', 'on_hold' => 'warning',
            'rejected', 'cancelled' => 'danger',
            'approved', 'assigned', 'accepted', 'in_progress', 'submitted' => 'info',
            default => 'default',
        };
    }

    private function approvalTone(string $status): string
    {
        return match ($status) {
            'approved' => 'success',
            'pending' => 'warning',
            'rejected' => 'danger',
            default => 'default',
        };
    }

    private function priorityTone(string $priorityCode): string
    {
        return match ($priorityCode) {
            'URGENT' => 'danger',
            'HIGH' => 'warning',
            'MEDIUM' => 'info',
            default => 'default',
        };
    }
}
