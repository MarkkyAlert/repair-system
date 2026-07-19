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

    /** SQL dump ที่สมบูรณ์และกู้คืนได้ของทุก base table (ทั้งโครงสร้าง + ข้อมูล). */
    public function toSql(): string
    {
        $out = "-- Repair System — database backup\n";
        $out .= '-- Generated: ' . date('Y-m-d H:i:s') . "\n";
        $out .= "-- Restore: import this file into an empty database (phpMyAdmin → Import, or mysql < file).\n\n";
        $out .= "SET NAMES utf8mb4;\n";
        $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

        foreach ($this->baseTables() as $table) {
            $out .= $this->dumpTable($table);
        }

        $out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

        return $out;
    }

    /** @return list<string> ชื่อ base table ในฐานข้อมูลที่เชื่อมต่ออยู่ (ไม่รวม view) */
    private function baseTables(): array
    {
        $rows = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return array_map(static fn (array $row): string => (string) $row[0], $rows);
    }

    private function dumpTable(string $table): string
    {
        $quoted = $this->quoteIdentifier($table);

        $create = $this->db->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
        $createSql = (string) ($create['Create Table'] ?? '');

        $out = "\n-- ----------------------------------------------------------------------------\n";
        $out .= '-- Table: ' . $table . "\n";
        $out .= "-- ----------------------------------------------------------------------------\n";
        $out .= 'DROP TABLE IF EXISTS ' . $quoted . ";\n";
        $out .= $createSql . ";\n\n";

        // ดึง row ทีละแถวแบบ stream กันตารางใหญ่ ๆ กินหน่วยความจำทั้งก้อน. แล้วรวม INSERT เป็นชุด ไฟล์จะได้เล็กลง.
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
                $out .= $this->insertStatement($quoted, $batch);
                $batch = [];
                $batchBytes = 0;
                $emitted = true;
            }
        }
        if ($batch !== []) {
            $out .= $this->insertStatement($quoted, $batch);
            $emitted = true;
        }
        if ($emitted) {
            $out .= "\n";
        }

        return $out;
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
