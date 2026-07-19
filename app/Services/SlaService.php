<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\TicketReadRepository;
use App\Repositories\TicketRepository;
use Throwable;

class SlaService
{
    public function __construct(
        private TicketRepository $tickets,
        private TicketReadRepository $reads,
        private NotificationService $notifications,
    ) {
    }

    public function processOverdueBreaches(): array
    {
        $breachedAt = date('Y-m-d H:i:s');
        $processed = 0;
        $notifyFailed = 0;
        $notifiedTickets = [];

        foreach ($this->reads->getPendingOverdueSlaBreaches() as $track) {
            $slaTrackId = (int) ($track['id'] ?? 0);
            $ticketId = (int) ($track['ticket_id'] ?? 0);
            $metricType = (string) ($track['metric_type'] ?? 'resolution');

            if ($slaTrackId <= 0 || $ticketId <= 0) {
                continue;
            }

            $marked = $this->tickets->markSlaBreachedById($slaTrackId, $breachedAt);
            if (!$marked) {
                continue;
            }

            $processed++;
            // แจ้งเตือนแบบ best-effort: การ breach ถูก commit ไปแล้ว (UPDATE คำสั่งเดียว) และ cron นี้
            // ประมวลผลเป็นชุด (BATCH). การแจ้งเตือนล้มเหลวของ ticket หนึ่งต้องไม่ทำให้ loop หยุด — ไม่งั้น
            // breach ที่เหลือในรอบนี้จะไม่ถูก mark/แจ้ง และ notify ของ ticket ตัวนี้เองก็จะไม่ถูก retry
            // (รอบถัดไปมันไม่ใช่ "pending" แล้ว). ให้ log แล้วทำต่อ.
            // notifySlaBreached คืนค่าว่า alert แบบ in-app ถูกเขียนจริงหรือไม่ (ตัว dispatch จะกลืน +
            // log ความล้มเหลวของตัวเอง) ดังนั้นความล้มเหลวที่ถูกกลืนก็ถูกนับด้วยแล้ว — ไม่ใช่แค่ตอน throw ออกมาตรง ๆ.
            $notified = true;
            try {
                $notified = $this->notifications->notifySlaBreached($ticketId, $metricType);
            } catch (Throwable $exception) {
                log_caught_exception('sla.breach.notify', $exception, ['ticket_id' => $ticketId, 'metric' => $metricType]);
                $notified = false;
            }
            if (!$notified) {
                $notifyFailed++;
            }
            $notifiedTickets[] = [
                'ticket_id' => $ticketId,
                'ticket_no' => (string) ($track['ticket_no'] ?? ''),
                'metric_type' => $metricType,
                'notified' => $notified,
            ];
        }

        // แยก "ถูก mark ว่า breached" (commit แล้ว) ออกจาก "แจ้งเตือนสำเร็จจริง": การแจ้งเตือนล้มเหลวต้องไม่
        // ถูกรายงานว่าเป็นการแจ้งเตือนที่สำเร็จ — การ breach ยังคงอยู่แต่ alert ไม่เคยส่งออกไป และจะไม่ถูก
        // retry (รอบถัดไปมันไม่ใช่ pending แล้ว). cron จะแสดงค่า notify_failed ออกมา.
        return [
            'processed' => $processed,
            'notified' => $processed - $notifyFailed,
            'notify_failed' => $notifyFailed,
            'items' => $notifiedTickets,
        ];
    }
}
