<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * export ฐานข้อมูลด้วย PHP ล้วน (ใช้ PDO) — สร้างไฟล์ MySQL .sql dump ที่กู้คืนได้ โดยไม่ต้องเรียก
 * mysqldump ผ่าน shell. บน shared hosting มักปิด proc_open/exec และไม่มี binary ของ mysqldump ให้ แอปเลย
 * ไม่มีทางสำรองข้อมูลในตัวก่อนอัปเดต. ตัวนี้มาอุดช่องว่างนั้น ให้ admin ดาวน์โหลด
 * backup จาก UI ได้เสมอ.
 */
class DatabaseExportService
{
    private const ROWS_PER_INSERT = 200;

    // คุมขนาดแต่ละ INSERT ให้ต่ำกว่า max_allowed_packet ค่าเริ่มต้น (มักราว 4 MB) เยอะ ๆ ไฟล์ dump จะได้กู้คืนได้ทุกโฮสต์.
    private const MAX_INSERT_BYTES = 512000;

    public function __construct(private PDO $db)
    {
    }

    /** บรรทัดปิดท้ายไฟล์ dump — มีบรรทัดนี้เท่านั้นถึงแปลว่า dump ออกมาครบ ไม่ได้ขาดกลางคัน. */
    public const COMPLETION_MARKER = '-- BACKUP COMPLETE';

    /**
     * SQL dump ที่สมบูรณ์และกู้คืนได้ของทุก base table — ส่งออกเป็นก้อน ๆ ผ่าน $emit ไม่เคยถือทั้งไฟล์ไว้ในหน่วยความจำ
     *
     * ตัวเลขที่ต้องรู้: ฐานข้อมูล 308 MB เคยทำให้ตัวเดิม (ที่ต่อสตริงทั้งก้อน) ตายที่ขีด 1 GB ฟีเจอร์นี้มีไว้สำหรับ
     * shared hosting ซึ่งมักตั้งเพดานหน่วยความจำไว้ที่ 128–256 MB เท่านั้น การส่งเป็นก้อนจึงไม่ใช่การจูนให้เร็วขึ้น
     * แต่เป็นเงื่อนไขที่ทำให้ฟีเจอร์นี้ใช้งานได้จริง
     *
     * @param callable(string): void $emit
     */
    public function streamTo(callable $emit): void
    {
        $emit("-- ระบบแจ้งซ่อม — database backup\n");
        $emit('-- Generated: ' . date('Y-m-d H:i:s') . "\n");
        $emit("-- Restore: import this file into an empty database (phpMyAdmin → Import, or mysql < file).\n\n");
        $emit("SET NAMES utf8mb4;\n");
        $emit("SET FOREIGN_KEY_CHECKS = 0;\n");
        $emit("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        // อ่านรายชื่อตารางให้เสร็จก่อน แล้วค่อยสั่ง connection ให้เลิก buffer ผลลัพธ์: ค่าเริ่มต้นคือดึงทั้งตารางมา
        // กองไว้ฝั่ง PHP ก่อนแล้วค่อยวนอ่าน ซึ่งกินหน่วยความจำเท่าขนาดตาราง — ส่งออกเป็นก้อนอย่างเดียวจึงยังไม่พอ
        $tables = $this->baseTables();
        $bufferedAttribute = self::unbufferedAttribute();
        $this->db->setAttribute($bufferedAttribute, false);

        try {
            foreach ($tables as $table) {
                $this->streamTable($table, $emit);
            }
        } finally {
            $this->db->setAttribute($bufferedAttribute, true);
        }

        $emit("\nSET FOREIGN_KEY_CHECKS = 1;\n");
        // ตัวปิดท้าย: ไฟล์ที่ขาดกลางคัน (โดนตัดเวลา/เน็ตหลุด) จะไม่มีบรรทัดนี้ ดูออกด้วยตาเปล่าโดยไม่ต้องกู้คืนก่อน
        $emit(self::COMPLETION_MARKER . ' ' . date('Y-m-d H:i:s') . "\n");
    }

    /**
     * SQL dump ทั้งก้อนเป็นสตริงเดียว — สะดวกสำหรับเทสต์และสคริปต์ที่รู้ว่าฐานข้อมูลเล็ก
     * เส้นทางที่ผู้ใช้กดจริงต้องใช้ streamTo() เพราะตัวนี้กินหน่วยความจำเท่าขนาดฐานข้อมูล
     */
    public function toSql(): string
    {
        $out = '';
        $this->streamTo(static function (string $chunk) use (&$out): void {
            $out .= $chunk;
        });

        return $out;
    }

    /** จำนวน base table ที่จะถูกสำรอง — ใช้เป็นด่านตรวจก่อนเริ่มส่งไฟล์ว่าฐานข้อมูลยังต่อติดและมีอะไรให้สำรองจริง. */
    public function countBaseTables(): int
    {
        return count($this->baseTables());
    }

    /** @return list<string> ชื่อ base table ในฐานข้อมูลที่เชื่อมต่ออยู่ (ไม่รวม view) */
    private function baseTables(): array
    {
        $rows = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn (array $row): string => (string) $row[0], $rows);
    }

    /** @param callable(string): void $emit */
    private function streamTable(string $table, callable $emit): void
    {
        $quoted = $this->quoteIdentifier($table);

        // ปิด cursor ทุกครั้ง: บน connection ที่ไม่ buffer ผลลัพธ์ที่ยังค้างอยู่จะบล็อกคำสั่งถัดไปทั้งหมด
        $createStmt = $this->db->query('SHOW CREATE TABLE ' . $quoted);
        $create = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createStmt->closeCursor();
        $createSql = (string) ($create['Create Table'] ?? '');

        $emit("\n-- ----------------------------------------------------------------------------\n");
        $emit('-- Table: ' . $table . "\n");
        $emit("-- ----------------------------------------------------------------------------\n");
        $emit('DROP TABLE IF EXISTS ' . $quoted . ";\n");
        $emit($createSql . ";\n\n");

        $stmt = $this->db->query('SELECT * FROM ' . $quoted);

        $batch = [];
        $batchBytes = 0;
        $emitted = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $tuple = $this->rowValues($row);
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
            // flush เมื่อชนเพดานจำนวน row หรือเพดานขนาด byte อย่างใดอย่างหนึ่ง กัน row เดียวที่กว้างมาก ๆ ทำให้ INSERT ใหญ่เกิน.
            if (count($batch) >= self::ROWS_PER_INSERT || $batchBytes >= self::MAX_INSERT_BYTES) {
                $emit($this->insertStatement($quoted, $batch));
                $batch = [];
                $batchBytes = 0;
                $emitted = true;
            }
        }
        // ปิด cursor ให้เรียบร้อยก่อนไปตารางถัดไป — ผลลัพธ์แบบไม่ buffer ที่ยังค้างอยู่จะบล็อกคำสั่งถัดไปบน connection เดิม
        $stmt->closeCursor();

        if ($batch !== []) {
            $emit($this->insertStatement($quoted, $batch));
            $emitted = true;
        }
        if ($emitted) {
            $emit("\n");
        }
    }

    /**
     * ค่า attribute "ไม่ buffer ผลลัพธ์" ของไดรเวอร์ MySQL — PHP 8.4 ย้ายไปไว้ใต้ Pdo\Mysql
     * ส่วน 8.2/8.3 (เวอร์ชันที่ CI รัน) ยังอยู่ที่ PDO เลยต้องเลือกตามเวอร์ชันที่รันจริง
     */
    private static function unbufferedAttribute(): int
    {
        /** @phpstan-ignore-next-line class_exists กันไว้สำหรับ PHP < 8.4 ที่ยังไม่มีคลาสนี้ */
        return class_exists('\Pdo\Mysql') ? \Pdo\Mysql::ATTR_USE_BUFFERED_QUERY : PDO::MYSQL_ATTR_USE_BUFFERED_QUERY;
    }

    /** @param array<string, mixed> $row @return string tuple `(v1, v2, ...)` ที่ทุกค่าถูก SQL-quote */
    private function rowValues(array $row): string
    {
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                // PDO::quote จะ escape ให้แล้วครอบ quote ให้; ตัวเลขที่อยู่ใน quote MySQL แปลงให้เองตอน insert. เลย quote
                // ทุกค่าที่ไม่ใช่ null ได้เลย ทั้งปลอดภัยและถูกต้องสำหรับ schema นี้ที่มีแต่ text/ตัวเลข/datetime.
                $values[] = $this->db->quote((string) $value);
            }
        }

        return '(' . implode(', ', $values) . ')';
    }

    /** @param list<string> $tuples */
    private function insertStatement(string $quotedTable, array $tuples): string
    {
        return 'INSERT INTO ' . $quotedTable . " VALUES\n" . implode(",\n", $tuples) . ";\n";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
