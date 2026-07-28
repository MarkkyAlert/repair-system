import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { mysqlInt, mysqlRows } from '../helpers/db';

const authDir = path.join(__dirname, '..', '.auth');
const adminState = path.join(authDir, 'admin.json');
const requesterState = path.join(authDir, 'requester.json');

test('executive report: admin downloads a real CSV and the export audit completes', async ({ browser }) => {
  const admin = await browser.newContext({ storageState: adminState });
  const baseline = mysqlInt('SELECT COALESCE(MAX(id), 0) FROM export_jobs');

  try {
    const page = await admin.newPage();
    await page.goto('/reports/executive');
    await expect(page.getByText('สรุป KPI เทียบงวด')).toBeVisible();
    await page.getByText('ส่งออกรายงาน', { exact: true }).click();

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'ส่งออก CSV' }).click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/^executive-summary-\d{8}-\d{6}\.csv$/);
    const downloadPath = await download.path();
    expect(downloadPath).not.toBeNull();
    const content = fs.readFileSync(downloadPath!, 'utf8');
    expect(Buffer.from(content).subarray(0, 3).toString('hex')).toBe('efbbbf');
    expect(content.trim().split(/\r?\n/).length).toBeGreaterThan(1);

    const jobs = mysqlRows(
      `SELECT e.type, e.format, e.status, e.file_name, u.username FROM export_jobs e ` +
        `INNER JOIN users u ON u.id = e.requested_by WHERE e.id > ${baseline} ORDER BY e.id`
    );
    expect(jobs).toHaveLength(1);
    expect(jobs[0][0]).toBe('executive_summary_report');
    expect(jobs[0][1]).toBe('csv');
    expect(jobs[0][2]).toBe('completed');
    expect(jobs[0][3]).toBe(download.suggestedFilename());
    expect(jobs[0][4]).toBe('admin');
  } finally {
    await admin.close();
  }
});

test('executive report: requester is redirected without an export action', async ({ browser }) => {
  const requester = await browser.newContext({ storageState: requesterState });
  try {
    const page = await requester.newPage();
    await page.goto('/reports/executive');
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByText('คุณไม่มีสิทธิ์เข้าถึงรายงาน')).toBeVisible();
    await expect(page.getByText('ส่งออกรายงาน')).toHaveCount(0);
  } finally {
    await requester.close();
  }
});
