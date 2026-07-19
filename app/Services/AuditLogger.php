<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;

/**
 * เขียน entry ของ audit-log พร้อมข้อมูลเสริมคือ IP + user-agent ของ request ปัจจุบัน.
 * แยกออกมาจาก AdminService::recordAudit เพื่อให้ทุก service ฝั่ง admin บันทึก log
 * ผ่าน single source เดียวกัน (ใช้ร่วมกันโดย AdminService / BroadcastService / …).
 */
class AuditLogger
{
    public function __construct(private AuditLogRepository $auditLogs)
    {
    }

    public function record(array $viewer, string $action, string $entityType, ?int $entityId = null, array $context = []): void
    {
        $request = request();
        $server = $request?->server ?? $_SERVER;
        $userAgent = substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 255);

        // best-effort: ทุกผู้เรียกจะบันทึก audit หลังการแก้ไขหลัก commit ไปแล้ว (หรือ side-effect
        // เช่น broadcast ถูกส่งออกไปแล้ว) การ insert audit ที่ล้มเหลวจึงต้องไม่โผล่มาเป็น error ให้ user
        // เห็น เพราะเขาจะนึกว่างานล้มเหลวแล้วลองใหม่ กลายเป็นส่งซ้ำสองครั้ง. ให้ log ไว้ในแท็บ
        // Security ของ admin แทน แล้วคงผลลัพธ์ว่าสำเร็จไว้.
        try {
            $this->auditLogs->record([
                'user_id' => (int) ($viewer['id'] ?? 0),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'ip_address' => substr((string) ($server['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $exception) {
            log_caught_exception('audit.record', $exception, [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }
    }
}
