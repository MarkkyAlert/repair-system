<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class GuestTicketRequestRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(array $payload): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO guest_ticket_requests (request_no, submission_token, asset_id, location_id, guest_name, guest_email, guest_phone, title, description, submitted_ip, status, created_at)
             VALUES (:request_no, :submission_token, :asset_id, :location_id, :guest_name, :guest_email, :guest_phone, :title, :description, :submitted_ip, "new", NOW())'
        );
        $stmt->execute([
            'request_no' => $payload['request_no'],
            'submission_token' => ($payload['submission_token'] ?? '') !== '' ? $payload['submission_token'] : null,
            'asset_id' => $payload['asset_id'] ?? null,
            'location_id' => $payload['location_id'] ?? null,
            'guest_name' => $payload['guest_name'],
            'guest_email' => $payload['guest_email'] !== '' ? $payload['guest_email'] : null,
            'guest_phone' => $payload['guest_phone'] !== '' ? $payload['guest_phone'] : null,
            'title' => $payload['title'],
            'description' => $payload['description'],
            'submitted_ip' => $payload['submitted_ip'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT g.*, a.asset_code, a.name AS asset_name, l.name AS location_name
             FROM guest_ticket_requests g
             LEFT JOIN assets a ON a.id = g.asset_id
             LEFT JOIN locations l ON l.id = g.location_id
             WHERE g.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listByStatus(string $status, int $limit, int $offset): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $where = '';
        $params = [];
        if (in_array($status, ['new', 'converted', 'rejected'], true)) {
            $where = 'WHERE g.status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(
            "SELECT g.id, g.request_no, g.guest_name, g.guest_email, g.guest_phone, g.title, g.description, g.status,
                    g.created_at, g.converted_ticket_id, a.asset_code, a.name AS asset_name, l.name AS location_name
             FROM guest_ticket_requests g
             LEFT JOIN assets a ON a.id = g.asset_id
             LEFT JOIN locations l ON l.id = g.location_id
             $where
             ORDER BY g.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countByStatus(): array
    {
        $stmt = $this->db->query('SELECT status, COUNT(*) AS total FROM guest_ticket_requests GROUP BY status');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totals = ['new' => 0, 'converted' => 0, 'rejected' => 0];
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (isset($totals[$key])) {
                $totals[$key] = (int) $row['total'];
            }
        }
        return $totals;
    }

    public function countMatching(string $status): int
    {
        $where = '';
        $params = [];
        if (in_array($status, ['new', 'converted', 'rejected'], true)) {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM guest_ticket_requests $where");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Atomically claim a 'new' request for conversion (new → converted) BEFORE the ticket is created.
     * Returns false when a concurrent convert/reject already moved it out of 'new' — the caller must
     * then NOT create a ticket. converted_ticket_id is filled afterwards via attachConvertedTicket().
     */
    public function claimForConversion(int $id, int $reviewerId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET status = "converted", reviewed_by = :reviewer, reviewed_at = NOW()
             WHERE id = :id AND status = "new"'
        );
        $stmt->execute([
            'reviewer' => $reviewerId,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function attachConvertedTicket(int $id, int $ticketId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET converted_ticket_id = :ticket_id
             WHERE id = :id'
        );
        $stmt->execute([
            'ticket_id' => $ticketId,
            'id' => $id,
        ]);
    }

    /**
     * Roll a claimed request back to 'new' when ticket creation fails, so it stays in the queue.
     * Guarded on converted_ticket_id IS NULL so a fully-converted request is never reverted.
     */
    public function revertConversionClaim(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET status = "new", reviewed_by = NULL, reviewed_at = NULL
             WHERE id = :id AND status = "converted" AND converted_ticket_id IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    public function markRejected(int $id, int $reviewerId, string $note): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE guest_ticket_requests
             SET status = "rejected", reviewed_by = :reviewer, reviewed_at = NOW(), review_note = :note
             WHERE id = :id AND status = "new"'
        );
        $stmt->execute([
            'reviewer' => $reviewerId,
            'note' => $note,
            'id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }
}
