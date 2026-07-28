import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

// Minimal mysql CLI shim for E2E assertions + teardown. The suite uses a dedicated test DB and
// records high-water marks before the run, so indirect rows (audit/export/login/email) are restored
// without touching rows that pre-date the run. Entity rows use an explicit E2E marker.

const XAMPP_MYSQL = '/Applications/XAMPP/xamppfiles/bin/mysql';
const MYSQL_BIN = process.env.MYSQL_BIN || (fs.existsSync(XAMPP_MYSQL) ? XAMPP_MYSQL : 'mysql');
const DB = process.env.TEST_DB_NAME || 'repair_system_test';
const HOST = process.env.DB_HOST || '127.0.0.1';
const USER = process.env.DB_USERNAME || 'root';
const PASS = process.env.DB_PASSWORD || '';
const ROOT = path.resolve(__dirname, '..', '..');
const BASELINE_FILE = path.resolve(__dirname, '..', '.auth', 'db-baseline.json');

type DatabaseBaseline = {
  audit_logs: number;
  email_queue: number;
  export_jobs: number;
  login_attempts: number;
};

export function mysqlExec(sql: string): string {
  const args = ['-h', HOST, '-u', USER];
  if (PASS !== '') args.push(`-p${PASS}`);
  args.push('-N', '-B', DB, '-e', sql);
  return execFileSync(MYSQL_BIN, args, { encoding: 'utf8' });
}

export function mysqlRows(sql: string): string[][] {
  const output = mysqlExec(sql).trimEnd();
  if (output === '') return [];
  return output.split('\n').map((line) => line.split('\t'));
}

export function mysqlValue(sql: string): string {
  return mysqlRows(sql)[0]?.[0] ?? '';
}

export function mysqlInt(sql: string): number {
  return Number(mysqlValue(sql) || 0);
}

/** A SQL string expression without quote/backslash ambiguity. */
export function sqlString(value: string): string {
  return `(CONVERT(0x${Buffer.from(value, 'utf8').toString('hex')} USING utf8mb4) COLLATE utf8mb4_unicode_ci)`;
}

export function captureE2EBaseline(): void {
  fs.mkdirSync(path.dirname(BASELINE_FILE), { recursive: true });
  const baseline: DatabaseBaseline = {
    audit_logs: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM audit_logs'),
    email_queue: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM email_queue'),
    export_jobs: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM export_jobs'),
    login_attempts: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM login_attempts'),
  };
  fs.writeFileSync(BASELINE_FILE, JSON.stringify(baseline));
}

function removeE2EAttachmentFiles(): void {
  const rows = mysqlRows(
    "SELECT DISTINCT ta.disk_path FROM ticket_attachments ta " +
      "INNER JOIN tickets t ON t.id = ta.ticket_id WHERE t.title LIKE 'E2E-%'"
  );
  const directories = new Set<string>();

  for (const [rawPath] of rows) {
    const relativePath = rawPath.replace(/^\/+/, '');
    if (!relativePath.startsWith('storage/uploads/tickets/')) continue;
    const absolutePath = path.resolve(ROOT, relativePath);
    if (!absolutePath.startsWith(path.resolve(ROOT, 'storage/uploads/tickets') + path.sep)) continue;
    fs.rmSync(absolutePath, { force: true });
    directories.add(path.dirname(absolutePath));
  }

  for (const directory of directories) {
    try {
      fs.rmdirSync(directory);
    } catch {
      // A non-empty directory can belong to a non-E2E attachment; leave it alone.
    }
  }
}

function readBaseline(): DatabaseBaseline | null {
  try {
    return JSON.parse(fs.readFileSync(BASELINE_FILE, 'utf8')) as DatabaseBaseline;
  } catch {
    return null;
  }
}

/** Delete everything created by this E2E run and remove uploaded files from the test filesystem. */
export function cleanupE2E(): void {
  removeE2EAttachmentFiles();

  // Guest notifications are not FK-linked to guest_ticket_requests, so remove them before the rows.
  mysqlExec(
    "DELETE nr FROM notification_recipients nr INNER JOIN notifications n ON n.id = nr.notification_id " +
      "INNER JOIN guest_ticket_requests g ON g.id = n.related_id " +
      "WHERE n.related_type = 'guest_request' AND (g.title LIKE 'E2E-%' OR g.guest_name LIKE 'E2E %')"
  );
  mysqlExec(
    "DELETE n FROM notifications n INNER JOIN guest_ticket_requests g ON g.id = n.related_id " +
      "WHERE n.related_type = 'guest_request' AND (g.title LIKE 'E2E-%' OR g.guest_name LIKE 'E2E %')"
  );

  // Converted guest requests prefix the ticket title with guest metadata, so title LIKE 'E2E-%'
  // cannot identify them. Delete through the persisted conversion link before removing guest rows.
  mysqlExec(
    "DELETE t FROM tickets t INNER JOIN guest_ticket_requests g ON g.converted_ticket_id = t.id " +
      "WHERE g.title LIKE 'E2E-%' OR g.guest_name LIKE 'E2E %'"
  );
  mysqlExec("DELETE FROM tickets WHERE title LIKE 'E2E-%'");
  mysqlExec("DELETE FROM guest_ticket_requests WHERE title LIKE 'E2E-%' OR guest_name LIKE 'E2E %'");

  // Polymorphic notifications have no FK. Sweep old E2E-era orphans too, so an interrupted
  // historical run cannot leave a bell item pointing at a row that no longer exists.
  mysqlExec(
    "DELETE FROM notification_recipients WHERE notification_id IN " +
      "(SELECT id FROM (SELECT n.id FROM notifications n LEFT JOIN guest_ticket_requests g ON g.id = n.related_id " +
      "WHERE n.related_type = 'guest_request' AND g.id IS NULL) x)"
  );
  mysqlExec(
    "DELETE n FROM notifications n LEFT JOIN guest_ticket_requests g ON g.id = n.related_id " +
      "WHERE n.related_type = 'guest_request' AND g.id IS NULL"
  );
  mysqlExec(
    "DELETE FROM notification_recipients WHERE notification_id IN " +
      "(SELECT id FROM (SELECT n.id FROM notifications n LEFT JOIN tickets t ON t.id = n.related_id " +
      "WHERE n.related_type = 'ticket' AND t.id IS NULL) x)"
  );
  mysqlExec(
    "DELETE n FROM notifications n LEFT JOIN tickets t ON t.id = n.related_id " +
      "WHERE n.related_type = 'ticket' AND t.id IS NULL"
  );

  mysqlExec("DELETE FROM password_resets WHERE email LIKE '%@e2e.test'");
  mysqlExec("DELETE FROM users WHERE username LIKE 'e2e%' OR email LIKE '%@e2e.test'");

  const baseline = readBaseline();
  if (baseline !== null) {
    mysqlExec(`DELETE FROM audit_logs WHERE id > ${baseline.audit_logs}`);
    mysqlExec(`DELETE FROM email_queue WHERE id > ${baseline.email_queue}`);
    mysqlExec(`DELETE FROM export_jobs WHERE id > ${baseline.export_jobs}`);
    mysqlExec(`DELETE FROM login_attempts WHERE id > ${baseline.login_attempts}`);
  }
  fs.rmSync(BASELINE_FILE, { force: true });
}
