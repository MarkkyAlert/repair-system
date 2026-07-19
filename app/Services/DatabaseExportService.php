<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * การ export ฐานข้อมูลแบบ PHP-native (ใช้ PDO ล้วน) — สร้างไฟล์ MySQL .sql dump ที่กู้คืนได้ โดยไม่ต้องเรียก
 * mysqldump ผ่าน shell. shared hosting (โฮสต์ที่ใช้ทรัพยากรร่วมกัน) มักปิด proc_open/exec และไม่มี binary ของ mysqldump มาให้ ทำให้
 * แอปไม่มีวิธีสำรองข้อมูลในตัวก่อนอัปเดต; ตัวนี้อุดช่องว่างนั้นเพื่อให้ admin ดาวน์โหลด
 * backup จาก UI ได้เสมอ.
 */
class DatabaseExportService
{
    private const ROWS_PER_INSERT = 200;

    // จำกัดขนาดแต่ละ INSERT ให้ต่ำกว่า max_allowed_packet เริ่มต้น (มักเป็น 4 MB) มาก ๆ เพื่อให้ dump กู้คืนได้บนทุกโฮสต์.
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

        // ดึง row แบบ stream เพื่อไม่ให้ตารางขนาดใหญ่ค้างอยู่ใน memory ทั้งหมด. รวม INSERT เป็นชุด (batch) เพื่อให้ไฟล์เล็กลง.
        $stmt = $this->db->query('SELECT * FROM ' . $quoted);
        $batch = [];
        $batchBytes = 0;
        $emitted = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $tuple = $this->rowValues($row);
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
            // flush เมื่อถึงเพดานจำนวน row หรือเพดานขนาด byte อย่างใดอย่างหนึ่ง เพื่อไม่ให้ row ที่กว้างมาก ๆ แถวเดียวสร้าง INSERT ที่ใหญ่เกิน.
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
                // PDO::quote จะ escape และครอบด้วย quote; MySQL จะแปลงค่าตัวเลขที่อยู่ใน quote ตอน insert ให้เอง ดังนั้นการ quote
                // ทุกค่าที่ไม่ใช่ null จึงทั้งปลอดภัยและถูกต้องสำหรับ schema ที่มีแต่ text/ตัวเลข/datetime นี้.
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
