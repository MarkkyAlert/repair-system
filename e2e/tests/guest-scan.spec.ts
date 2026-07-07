import { test, expect } from '@playwright/test';

// Golden path B — public guest reports a problem by scanning an asset QR.
// This is the highest-risk flow (used by outsiders, no login). Token is a seeded QR
// (asset AST-PRN-0001); override with E2E_SCAN_TOKEN.
const TOKEN = process.env.E2E_SCAN_TOKEN || '8f1b9c6d2a4e7f8091b2c3d4e5f60718';

test('golden path B — guest submits a repair request via QR scan', async ({ page }) => {
  // scan landing shows the asset and the no-login report entry
  await page.goto(`/scan/${TOKEN}`);
  await expect(page.getByText('AST-PRN-0001')).toBeVisible();

  await page.getByRole('link', { name: 'แจ้งปัญหา (ไม่ต้อง login)' }).click();
  await expect(page).toHaveURL(new RegExp(`/scan/${TOKEN}/report`));

  // fill + submit the guest report form (leave the honeypot "website" empty)
  const stamp = Date.now();
  await page.fill('input[name="guest_name"]', 'E2E Guest');
  await page.fill('input[name="guest_phone"]', '0810009999');
  await page.fill('input[name="title"]', `E2E-B-${stamp} guest report`);
  await page.fill('textarea[name="description"]', 'E2E automated guest report — safe to delete.');
  await page.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).click();

  // confirmation page with a real reference number (GR-YYYYMMDD-xxxxxx)
  await expect(page.getByText('ขอบคุณที่แจ้งปัญหา')).toBeVisible();
  await expect(page.getByText(/GR-\d{8}-[0-9a-fA-F]+/)).toBeVisible();
});
