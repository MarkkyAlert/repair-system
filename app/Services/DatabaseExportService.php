<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * PHP-native (pure PDO) database export — produces a restorable MySQL .sql dump without shelling out to
 * mysqldump. Shared hosting frequently disables proc_open/exec and does not ship the mysqldump binary, which
 * left the app with no in-product way to back up before an update; this closes that gap so an admin can always
 * download a backup from the UI.
 */
class DatabaseExportService
{
    private const ROWS_PER_INSERT = 200;

    // Cap each INSERT well under a default max_allowed_packet (often 4 MB) so the dump restores on any host.
    private const MAX_INSERT_BYTES = 512000;

    public function __construct(private PDO $db)
    {
    }

    /** A complete, restorable SQL dump of every base table (structure + data). */
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

    /** @return list<string> base table names in the connected database (views excluded) */
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

        // Stream rows so a large table doesn't buffer entirely in memory. Batch INSERTs for a smaller file.
        $stmt = $this->db->query('SELECT * FROM ' . $quoted);
        $batch = [];
        $batchBytes = 0;
        $emitted = false;
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $tuple = $this->rowValues($row);
            $batch[] = $tuple;
            $batchBytes += strlen($tuple);
            // Flush on either a row-count or a byte-size ceiling so one wide row can't build an oversized INSERT.
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

    /** @param array<string, mixed> $row @return string a `(v1, v2, ...)` tuple with each value SQL-quoted */
    private function rowValues(array $row): string
    {
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                // PDO::quote escapes and wraps in quotes; MySQL coerces quoted numerics on insert, so quoting
                // every non-null value is both safe and correct for this all-text/numeric/datetime schema.
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
