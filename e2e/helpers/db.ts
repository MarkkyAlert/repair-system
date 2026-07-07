import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

// Minimal mysql CLI shim for E2E teardown. E2E creates real rows through the UI (ticket, guest
// request); this removes anything marked with the "E2E-" title prefix so residue never accumulates
// in the test DB. Uses the XAMPP mysql binary by default; override with MYSQL_BIN.

const XAMPP_MYSQL = '/Applications/XAMPP/xamppfiles/bin/mysql';
const MYSQL_BIN = process.env.MYSQL_BIN || (fs.existsSync(XAMPP_MYSQL) ? XAMPP_MYSQL : 'mysql');
const DB = process.env.TEST_DB_NAME || 'repair_system_test';
const HOST = process.env.DB_HOST || '127.0.0.1';
const USER = process.env.DB_USERNAME || 'root';
const PASS = process.env.DB_PASSWORD || '';

export function mysqlExec(sql: string): string {
  const args = ['-h', HOST, '-u', USER];
  if (PASS !== '') args.push(`-p${PASS}`);
  args.push('-N', '-B', DB, '-e', sql);
  return execFileSync(MYSQL_BIN, args, { encoding: 'utf8' });
}

/** Delete everything the E2E run created (marker: title/guest_name starts with "E2E"). Children cascade. */
export function cleanupE2E(): void {
  mysqlExec("DELETE FROM tickets WHERE title LIKE 'E2E-%'");
  mysqlExec("DELETE FROM guest_ticket_requests WHERE title LIKE 'E2E-%' OR guest_name LIKE 'E2E %'");
  // Sweep ticket notifications orphaned by the ticket deletes above.
  mysqlExec(
    "DELETE FROM notification_recipients WHERE notification_id IN " +
      "(SELECT id FROM (SELECT n.id FROM notifications n LEFT JOIN tickets t ON t.id = n.related_id " +
      "WHERE n.related_type = 'ticket' AND t.id IS NULL) x)"
  );
  mysqlExec(
    "DELETE n FROM notifications n LEFT JOIN tickets t ON t.id = n.related_id " +
      "WHERE n.related_type = 'ticket' AND t.id IS NULL"
  );
}
