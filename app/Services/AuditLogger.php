<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuditLogRepository;

/**
 * Writes an audit-log entry enriched with the current request's IP + user-agent.
 * Extracted from AdminService::recordAudit so every admin-facing service logs
 * through a single source (shared by AdminService / BroadcastService / …).
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

        // Best-effort: every caller records the audit AFTER the primary mutation has committed (or the
        // side-effect — e.g. a broadcast — has already been sent), so a failed audit insert must NOT surface as
        // an error to the user (they'd think the action failed and retry, double-sending). Log it for the admin
        // Security tab instead and keep the success response. (logic-review R7-F4, owner-confirmed best-effort)
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
