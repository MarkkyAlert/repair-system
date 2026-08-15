<?php
declare(strict_types=1);

use App\Repositories\TicketReadRepository;
use App\Services\TicketService;

// หัวหน้าที่กำลังจะมอบหมายงานต้องรู้ว่าช่างคนไหนมือว่าง ตัวเลขงานค้างมีอยู่แล้วในรายงานผลงานทีมช่าง แต่คนละหน้า
// กับจุดที่ตัดสินใจ — เดิมช่องเลือกช่างมีแต่ชื่อ ทำให้ต้องเปิดสองหน้าแล้วจำตัวเลขมาเลือก. ตอนนี้ getActiveTechnicians()
// แนบ open_now มาด้วย และ view-model แปะไว้ในป้ายของแต่ละตัวเลือก.
//
// สิ่งที่เทสต์นี้ล็อกไว้:
//   1. นับเฉพาะงานที่ยังอยู่ในมือช่างจริง (assigned/accepted/in_progress) — resolved/completed/cancelled ถือว่าพ้นมือแล้ว
//   2. ช่างที่ไม่มีงานต้องยังอยู่ในรายการ (LEFT JOIN ไม่ใช่ INNER JOIN) ไม่งั้นคนที่ว่างที่สุดจะหายไปจากช่องเลือก
//   3. ป้ายในช่องเลือกช่างมีตัวเลขจริง ไม่ใช่แค่ repository คืนค่ามาแล้วไม่มีใครใช้
//   4. ยังเป็นคิวรีเดียว ไม่ใช่ยิงต่อช่างหนึ่งคน (N+1)

function awh_pdo(): PDO
{
    return tvm_container()->get(PDO::class);
}

/** สร้างช่างจริงในฐานทดสอบ (ชื่อผู้ใช้สุ่ม) — เทสต์ต้องไม่ผูกกับจำนวนช่างที่มีอยู่ก่อน */
function awh_make_technician(string $name): int
{
    $suffix = strtolower(bin2hex(random_bytes(4)));
    awh_pdo()->prepare(
        "INSERT INTO users (username, email, password_hash, full_name, role, is_active, created_at, updated_at)
         VALUES (:u, :e, :p, :n, 'technician', 1, NOW(), NOW())"
    )->execute([
        'u' => 'awh_' . $suffix,
        'e' => 'awh_' . $suffix . '@example.test',
        'p' => password_hash('x-' . $suffix, PASSWORD_BCRYPT),
        'n' => $name . ' ' . strtoupper(substr($suffix, 0, 4)),
    ]);

    return (int) awh_pdo()->lastInsertId();
}

function awh_make_ticket(int $technicianId, string $status): int
{
    awh_pdo()->prepare(
        "INSERT INTO tickets (ticket_no, title, description, requester_id, location_id, ticket_category_id, priority_id,
                              status, approval_status, assigned_technician_id, created_at, updated_at)
         VALUES (:no, 'awh load probe', 'x', 1, 1, 1, 1, :status, 'approved', :tech, NOW(), NOW())"
    )->execute([
        'no' => 'AWH-' . strtoupper(bin2hex(random_bytes(4))),
        'status' => $status,
        'tech' => $technicianId,
    ]);

    return (int) awh_pdo()->lastInsertId();
}

/** งานค้างของช่างคนหนึ่งตามที่ repository รายงาน (null = ไม่อยู่ในรายการเลย) */
function awh_open_now(int $technicianId): ?int
{
    foreach (tvm_container()->get(TicketReadRepository::class)->getActiveTechnicians() as $row) {
        if ((int) $row['id'] === $technicianId) {
            return (int) ($row['open_now'] ?? -1);
        }
    }

    return null;
}

test('assign: ช่องเลือกช่างบอกงานค้างของแต่ละคน และนับเฉพาะงานที่ยังอยู่ในมือช่าง', function (): void {
    $busy = awh_make_technician('ช่างมีงาน');
    $idle = awh_make_technician('ช่างว่าง');
    $ticketIds = [];

    try {
        // ก่อนมอบหมายอะไร ทั้งคู่ต้องอยู่ในรายการและงานค้างเป็น 0
        assert_same(0, awh_open_now($busy), 'ช่างที่เพิ่งสร้างยังไม่มีงานค้าง');
        assert_same(0, awh_open_now($idle), 'ช่างที่ไม่มีงานต้องยังอยู่ในรายการ (LEFT JOIN ไม่ใช่ INNER JOIN)');

        // งานที่ยังอยู่ในมือช่าง 3 สถานะ
        foreach (['assigned', 'accepted', 'in_progress'] as $status) {
            $ticketIds[] = awh_make_ticket($busy, $status);
        }
        assert_same(3, awh_open_now($busy), 'assigned/accepted/in_progress = งานที่ยังอยู่ในมือช่าง');

        // งานที่พ้นมือช่างไปแล้วต้องไม่ถูกนับ — ไม่งั้นช่างที่ทำงานเสร็จเยอะจะดูเหมือนคนที่งานล้นมือ
        foreach (['resolved', 'completed', 'cancelled'] as $status) {
            $ticketIds[] = awh_make_ticket($busy, $status);
        }
        assert_same(3, awh_open_now($busy), 'resolved/completed/cancelled พ้นมือช่างแล้ว ไม่นับเป็นงานค้าง');
        assert_same(0, awh_open_now($idle), 'งานของช่างคนอื่นไม่รั่วมาที่ช่างว่าง');

        // ช่างที่ถูกปิดบัญชีต้องหายจากช่องเลือก (พฤติกรรมเดิม ต้องไม่พังเพราะเพิ่ม JOIN)
        awh_pdo()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$idle]);
        assert_same(null, awh_open_now($idle), 'ช่างที่ถูกปิดบัญชีไม่อยู่ในช่องเลือก');
        awh_pdo()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$idle]);

        // ตัวเลขต้องไปโผล่ในป้ายที่ผู้ใช้เห็นจริง ไม่ใช่ค้างอยู่แค่ชั้น repository
        $detail = tvm_container()->get(TicketService::class)->getTicketDetailData(
            $ticketIds[0],
            ['id' => 4, 'role' => 'admin']
        );
        $labels = [];
        foreach ($detail['workflow']['technicians'] ?? [] as $option) {
            $labels[(int) $option['id']] = (string) $option['label'];
        }
        assert_true(isset($labels[$busy]) && isset($labels[$idle]), 'ช่างทั้งสองคนอยู่ในช่องเลือกของหน้ารายละเอียด');
        assert_true(str_contains($labels[$busy], 'งานค้าง 3'), 'ป้ายของช่างที่มีงานบอกจำนวนงานค้าง: ' . $labels[$busy]);
        assert_true(str_contains($labels[$idle], 'ว่าง'), 'ป้ายของช่างที่ไม่มีงานบอกว่าว่าง: ' . $labels[$idle]);
    } finally {
        if ($ticketIds !== []) {
            awh_pdo()->query('DELETE FROM tickets WHERE id IN (' . implode(',', array_map('intval', $ticketIds)) . ')');
        }
        awh_pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$busy, $idle]);
    }
});

test('assign: งานค้างของช่างทุกคนมาจากคิวรีเดียว ไม่ใช่ยิงต่อช่างหนึ่งคน', function (): void {
    $extra = [awh_make_technician('ช่างนับคิวรี ก'), awh_make_technician('ช่างนับคิวรี ข')];

    try {
        $queries = count_queries(static function (): void {
            tvm_container()->get(TicketReadRepository::class)->getActiveTechnicians();
        });

        assert_same(1, $queries, 'รายชื่อช่าง + งานค้างต้องได้จากคิวรีเดียว (กัน N+1 ตอนมีช่างหลายสิบคน)');
    } finally {
        awh_pdo()->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute($extra);
    }
});
