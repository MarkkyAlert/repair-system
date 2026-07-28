import { expect, test } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { mysqlRows } from '../helpers/db';

const requesterState = path.join(__dirname, '..', '.auth', 'requester.json');
const root = path.resolve(__dirname, '..', '..');

test('ticket attachment: upload reaches DB/disk, reloads, downloads, and rejects an anonymous request', async ({
  browser,
}) => {
  const requester = await browser.newContext({ storageState: requesterState });
  const anonymous = await browser.newContext();
  const stamp = Date.now();
  const title = `E2E-UPLOAD-${stamp} ticket`;
  const fileName = `e2e-evidence-${stamp}.txt`;
  const content = `E2E attachment ${stamp}\n`;

  try {
    const page = await requester.newPage();
    await page.goto('/tickets/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', 'E2E upload integration test.');
    await page.selectOption('select[name="priority_id"]', { index: 1 });
    await page.selectOption('select[name="ticket_category_id"]', { index: 1 });
    await page.selectOption('select[name="location_id"]', { index: 1 });
    await page.setInputFiles('#attachments', {
      name: fileName,
      mimeType: 'text/plain',
      buffer: Buffer.from(content),
    });
    await page.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).click();

    await expect(page).toHaveURL(/\/tickets\/\d+/);
    const ticketId = Number(page.url().match(/\/tickets\/(\d+)/)![1]);
    const attachmentLink = page.getByRole('link', { name: new RegExp(fileName) });
    await expect(attachmentLink).toBeVisible();

    const row = mysqlRows(
      `SELECT id, original_name, disk_path, mime_type, file_size FROM ticket_attachments ` +
        `WHERE ticket_id = ${ticketId}`
    );
    expect(row).toHaveLength(1);
    const [attachmentId, originalName, diskPath, mimeType, fileSize] = row[0];
    expect([originalName, mimeType, fileSize]).toEqual([fileName, 'text/plain', String(Buffer.byteLength(content))]);
    expect(fs.readFileSync(path.resolve(root, diskPath), 'utf8')).toBe(content);
    expect(mysqlRows(`SELECT status FROM tickets WHERE id = ${ticketId}`)).toEqual([['pending_approval']]);

    const href = (await attachmentLink.getAttribute('href'))!;
    const download = await requester.request.get(href);
    expect(download.status()).toBe(200);
    expect(await download.text()).toBe(content);
    expect(download.headers()['content-type']).toContain('text/plain');

    const denied = await anonymous.request.get(`/attachments/${attachmentId}`, { maxRedirects: 0 });
    expect(denied.status()).toBe(302);
    expect(denied.headers().location).toContain('/login');

    await page.reload();
    await expect(page.getByRole('link', { name: new RegExp(fileName) })).toBeVisible();
  } finally {
    await requester.close();
    await anonymous.close();
  }
});
