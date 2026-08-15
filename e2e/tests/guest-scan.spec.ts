import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';
import { mysqlInt, mysqlRows, sqlString } from '../helpers/db';

// Golden path B — public guest reports a problem by scanning an asset QR.
// This is the highest-risk flow (used by outsiders, no login). Token is a seeded QR
// (asset AST-PRN-0001); override with E2E_SCAN_TOKEN.
const TOKEN = process.env.E2E_SCAN_TOKEN || '8f1b9c6d2a4e7f8091b2c3d4e5f60718';
const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

test('golden path B — guest submits by QR, admin converts, and guest sees the linked ticket', async ({
  page,
  browser,
}) => {
  // scan landing shows the asset and the no-login report entry
  await page.goto(`/scan/${TOKEN}`);
  await expect(page.getByText('AST-PRN-0001')).toBeVisible();

  await page.getByRole('link', { name: 'แจ้งปัญหา (ไม่ต้อง login)' }).click();
  await expect(page).toHaveURL(new RegExp(`/scan/${TOKEN}/report`));

  // fill + submit the guest report form (leave the honeypot "website" empty)
  const stamp = Date.now();
  const title = `E2E-B-${stamp} guest report`;
  await page.fill('input[name="guest_name"]', 'E2E Guest');
  await page.fill('input[name="guest_phone"]', '0810009999');
  await page.fill('input[name="title"]', title);
  await page.fill('textarea[name="description"]', 'E2E automated guest report — safe to delete.');
  await page.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).click();

  // confirmation page with a real reference number (GR-YYYYMMDD-xxxxxx)
  await expect(page.getByText('ขอบคุณที่แจ้งปัญหา')).toBeVisible();
  const reference = (await page.getByText(/GR-\d{8}-[0-9a-fA-F]+/).textContent())!.trim();

  // The reference is the guest's only handle on the request, and it is read on a phone. The copy button is
  // hidden in the HTML and revealed by JS, so its visibility proves the script ran; the clipboard read proves
  // the click actually copied the reference rather than just changing the label.
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
  const copyReference = page.getByRole('button', { name: 'คัดลอกเลขที่อ้างอิง' });
  await expect(copyReference).toBeVisible();
  await copyReference.click();
  expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(reference);
  await expect(page.getByRole('button', { name: 'คัดลอกแล้ว' })).toBeVisible();

  // The reference card is a light panel on a page that is dark in both themes, so the dark theme is where its
  // text can go light-on-light. The a11y sweep only visits authenticated pages and this one exists only as the
  // response to a POST, so check it here — in the theme that broke it — instead of leaving it uncovered.
  await page.evaluate(() => document.documentElement.classList.add('dark'));

  const axe = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .include('.reference-card')
    .analyze();
  const blocking = axe.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  expect(blocking, `\n${blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}`).join('\n')}`).toEqual([]);

  // axe reports contrast over a gradient as "incomplete", never as a violation, so it cannot see this card at
  // all — measure it directly instead, off the resolved styles the browser actually applied.
  const cardContrast = await page.evaluate(() => {
    const card = document.querySelector('.reference-card') as HTMLElement;
    const label = document.querySelector('.reference-label') as HTMLElement;
    const colors = (value: string) =>
      (value.match(/rgba?\([^)]+\)/g) ?? []).map((c) => (c.match(/[\d.]+/g) ?? []).map(Number));
    const luminance = ([r, g, b]: number[]) => {
      const f = (c: number) => (c / 255 <= 0.03928 ? c / 255 / 12.92 : ((c / 255 + 0.055) / 1.055) ** 2.4);
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const stops = colors(getComputedStyle(card).backgroundImage);
    const text = colors(getComputedStyle(label).color)[0];
    const ratios = stops.map((stop) => {
      const [a, b] = [luminance(text), luminance(stop)].sort((x, y) => y - x);
      return (a + 0.05) / (b + 0.05);
    });

    return {
      worst: Math.min(...ratios),
      seeThrough: stops.some((stop) => stop.length > 3 && stop[3] < 1),
    };
  });
  // A translucent stop would let the dark page through the card, so the measurement above would not describe
  // what the eye sees either.
  expect(cardContrast.seeThrough, 'the reference card must be opaque').toBe(false);
  expect(cardContrast.worst).toBeGreaterThanOrEqual(4.5);

  await page.evaluate(() => document.documentElement.classList.remove('dark'));

  const request = mysqlRows(
    `SELECT g.id, g.request_no, g.status, g.guest_phone, a.asset_code ` +
      `FROM guest_ticket_requests g LEFT JOIN assets a ON a.id = g.asset_id ` +
      `WHERE g.title = ${sqlString(title)}`
  );
  expect(request).toHaveLength(1);
  const requestId = Number(request[0][0]);
  expect(requestId).toBeGreaterThan(0);
  expect(request[0].slice(1)).toEqual([reference, 'new', '0810009999', 'AST-PRN-0001']);
  expect(
    mysqlInt(
      `SELECT COUNT(*) FROM notifications WHERE related_type = 'guest_request' ` +
        `AND related_id = (SELECT id FROM guest_ticket_requests WHERE title = ${sqlString(title)})`
    )
  ).toBeGreaterThan(0);

  // Negative lookup does not leak the request, then the same UI recovers with the correct contact.
  await page.getByRole('link', { name: 'ติดตามสถานะคำขอ' }).click();
  await page.fill('input[name="contact"]', '0899999999');
  await page.getByRole('button', { name: 'เช็คสถานะ' }).click();
  await expect(page.getByText(/ไม่พบข้อมูล/)).toBeVisible();

  await page.fill('input[name="contact"]', '0810009999');
  await page.getByRole('button', { name: 'เช็คสถานะ' }).click();
  await expect(page.getByText(reference)).toBeVisible();
  await expect(page.getByText(/ทีมงานกำลังตรวจสอบคำขอ/)).toBeVisible();

  // Admin moderation completes the public-to-internal handoff.
  const admin = await browser.newContext({ storageState: adminState });
  try {
    const moderation = await admin.newPage();
    await moderation.goto('/admin/guest-requests?status=new');
    const requestPanel = moderation.locator('details', { hasText: title });
    await expect(requestPanel).toHaveCount(1);
    await requestPanel.locator('summary').click();
    const convertForm = requestPanel.locator('form[action$="/convert"]');
    await convertForm.locator('select[name="priority_id"]').selectOption({ index: 1 });
    await convertForm.locator('select[name="ticket_category_id"]').selectOption({ index: 1 });
    moderation.on('dialog', (dialog) => dialog.accept());
    await convertForm.getByRole('button', { name: 'แปลงเป็น Ticket' }).click();
    await expect(moderation.getByText('แปลงเป็น Ticket เรียบร้อยแล้ว')).toBeVisible();

    const converted = mysqlRows(
      `SELECT g.status, g.converted_ticket_id, t.status, t.channel, a.asset_code ` +
        `FROM guest_ticket_requests g INNER JOIN tickets t ON t.id = g.converted_ticket_id ` +
        `LEFT JOIN assets a ON a.id = t.asset_id WHERE g.id = ${requestId}`
    );
    expect(converted).toHaveLength(1);
    const convertedTicketId = Number(converted[0][1]);
    expect(convertedTicketId).toBeGreaterThan(0);
    expect([converted[0][0], converted[0][2], converted[0][3], converted[0][4]]).toEqual([
      'converted',
      'pending_approval',
      'qr',
      'AST-PRN-0001',
    ]);

    await moderation.goto('/admin/guest-requests?status=converted');
    const convertedPanel = moderation.locator('details', { hasText: title });
    await convertedPanel.locator('summary').click();
    await expect(convertedPanel.getByRole('link', { name: `ดู Ticket #${convertedTicketId}` })).toBeVisible();
  } finally {
    await admin.close();
  }

  // The same public tracking form reflects the internal conversion after a fresh request.
  await page.fill('input[name="request_no"]', reference);
  await page.fill('input[name="contact"]', '0810009999');
  await page.getByRole('button', { name: 'เช็คสถานะ' }).click();
  await expect(page.getByText(/รับเรื่องแล้ว · แปลงเป็นงานเลขที่/)).toBeVisible();
  await expect(page.getByText('รออนุมัติ').first()).toBeVisible();
});
