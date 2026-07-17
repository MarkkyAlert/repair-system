<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\View;
use App\Repositories\TicketReadRepository;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Ticket print/export concern split out of TicketService: printable HTML view-model,
 * Job Order PDF (Dompdf), and the print QR PNG. Reuses TicketService::mapTicketDetail()
 * for the shared ticket-detail mapping (single source) and TicketReadRepository for visibility.
 */
class TicketPrintService
{
    public function __construct(
        private TicketReadRepository $reads,
        private TicketService $ticketService,
    ) {
    }

    public function getPrintableTicketData(int $ticketId, array $viewer, string $paper = 'a4'): ?array
    {
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
        if ($ticket === null) {
            return null;
        }

        $mapped = $this->ticketService->mapTicketDetail($ticket);
        $paper = $this->normalizePaperSize($paper);

        return [
            'paper' => $paper,
            'paper_label' => strtoupper($paper),
            'printed_at' => thai_datetime(time()),
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
        // Writable, portable temp dir for Dompdf. sys_get_temp_dir() can be empty/non-writable under
        // macOS Apache; /tmp is world-writable on Linux + macOS. Fall back to an app-writable dir last.
        $dompdfTmp = sys_get_temp_dir();
        if ($dompdfTmp === '' || !@is_writable($dompdfTmp)) {
            $dompdfTmp = is_dir('/tmp') ? '/tmp' : BASE_PATH . '/storage/uploads';
        }
        $options->setTempDir($dompdfTmp);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sarabun');
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
        $ticket = $this->reads->findVisibleTicketById($ticketId, $viewer);
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

    private function normalizePaperSize(string $paper): string
    {
        $paper = strtolower(trim($paper));

        return in_array($paper, ['a4', 'a5'], true) ? $paper : 'a4';
    }
}
