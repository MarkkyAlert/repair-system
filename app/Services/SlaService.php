<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SettingsRepository;
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
            // ประมวลผลทีละชุด. การแจ้งเตือนล้มเหลวของ ticket หนึ่งต้องไม่ทำให้ loop หยุด — ไม่งั้น
            // breach ที่เหลือในรอบนี้จะไม่ถูก mark หรือแจ้ง และ notify ของ ticket ตัวนี้เองก็ไม่ถูก retry
            // (รอบถัดไปมันไม่ใช่ "pending" แล้ว). เลย log แล้วทำต่อ.
            // notifySlaBreached คืนค่าว่า alert แบบ in-app ถูกเขียนจริงไหม (ตัว dispatch จะกลืนแล้ว
            // log ความล้มเหลวของตัวเอง) ความล้มเหลวที่ถูกกลืนจึงถูกนับด้วยแล้ว ไม่ใช่แค่ตอน throw ออกมาตรง ๆ.
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
        // ถูกรายงานว่าแจ้งสำเร็จ — การ breach ยังอยู่แต่ alert ไม่เคยส่งออกไป และจะไม่ถูก
        // retry (รอบถัดไปมันไม่ใช่ pending แล้ว). cron จะแสดงค่า notify_failed ออกมา.
        return [
            'processed' => $processed,
            'notified' => $processed - $notifyFailed,
            'notify_failed' => $notifyFailed,
            'items' => $notifiedTickets,
        ];
    }

    /**
     * บันทึกจำนวนการแจ้งเตือน SLA ที่ส่งไม่สำเร็จลง flag ที่ dashboard ใช้เตือน — แบบ "ค้างสะสม" ไม่ใช่ heartbeat
     * รอบล่าสุด. ต่างจาก cron งานอื่น (คิวอีเมล/สำรอง/ล้างไฟล์) ที่ "ทำซ้ำทุกรอบ" — flag ของมันจึงกลับเป็น 0 เองเมื่อ
     * ปัญหาหาย. แต่การแจ้งเตือน SLA ที่ล้มเหลว "ไม่ถูก retry" (breach ถูก mark ไปแล้ว รอบถัดไปไม่ใช่ pending) ถ้าเขียน
     * ทับด้วยค่ารอบล่าสุด (0) รอบสะอาดถัดไปจะลบสัญญาณทิ้ง แอดมินไม่มีวันเห็นว่ามี breach ที่ไม่เคยแจ้งใครเลย. จึงเขียน
     * เฉพาะตอนมีความล้มเหลวใหม่ โดยบวกสะสมกับค่าเดิม (คงค้างจนแอดมินไปเคลียร์เอง).
     */
    public function recordNotifyFailureFlag(SettingsRepository $settings, int $notifyFailed, string $key = 'cron_sla_notify_last_failed'): void
    {
        if ($notifyFailed <= 0) {
            return; // รอบสะอาด: ห้ามลบสัญญาณเดิมทิ้ง
        }

        $existingRow = $settings->getByKey($key);
        $existing = is_array($existingRow) ? (int) ($existingRow['setting_value'] ?? 0) : 0;
        $settings->upsert($key, (string) (max(0, $existing) + $notifyFailed), 'string', false, 0);
    }
}
