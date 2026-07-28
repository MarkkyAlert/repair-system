import { expect, test } from '@playwright/test';
import path from 'node:path';
import { mysqlInt, mysqlRows, mysqlValue, sqlString } from '../helpers/db';

const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

test('password reset: UI queues a one-time link, changes the password, and the new login works', async ({
  browser,
}) => {
  const admin = await browser.newContext({ storageState: adminState });
  const guest = await browser.newContext();
  const stamp = Date.now();
  const username = `e2er${stamp}`;
  const email = `${username}@e2e.test`;
  const oldPassword = 'E2eOldPass123!';
  const newPassword = 'E2eNewPass456!';

  try {
    // Create an isolated account through the real admin UI; global teardown removes it.
    const create = await admin.newPage();
    await create.goto('/admin#tab-users');
    const createPanel = create.locator('details', { hasText: 'สร้างบัญชีผู้ใช้งาน' }).first();
    await createPanel.locator('summary').click();
    await createPanel.locator('#new_username').fill(username);
    await createPanel.locator('#new_full_name').fill(`E2E Reset ${stamp}`);
    await createPanel.locator('#new_email').fill(email);
    await createPanel.locator('#new_password').fill(oldPassword);
    await createPanel.locator('#new_password_confirmation').fill(oldPassword);
    await createPanel.getByRole('button', { name: 'สร้างบัญชีผู้ใช้งาน' }).click();
    await expect(create.getByText('สร้างบัญชีผู้ใช้งานเรียบร้อยแล้ว')).toBeVisible();
    expect(mysqlValue(`SELECT id FROM users WHERE email = ${sqlString(email)}`)).toMatch(/^\d+$/);

    const page = await guest.newPage();
    await page.goto('/forgot-password');
    await page.fill('input[name="email"]', email);
    await page.getByRole('button', { name: 'ส่งลิงก์ตั้งรหัสผ่าน' }).click();
    await expect(page.getByText('หากอีเมลนี้มีอยู่ในระบบ ระบบได้สร้างคำขอรีเซ็ตรหัสผ่านให้แล้ว')).toBeVisible();

    // email_queue is the safe test inbox: no external SMTP delivery is attempted.
    const queued = mysqlRows(
      `SELECT status, payload FROM email_queue WHERE to_email = ${sqlString(email)} ORDER BY id DESC LIMIT 1`
    );
    expect(queued).toHaveLength(1);
    expect(queued[0][0]).toBe('queued');
    const payload = JSON.parse(queued[0][1]) as { template: string; reset_url: string };
    expect(payload.template).toBe('password_reset');
    expect(mysqlInt(`SELECT COUNT(*) FROM password_resets WHERE email = ${sqlString(email)}`)).toBe(1);

    const resetUrl = new URL(payload.reset_url, 'http://e2e.local');
    const resetPath = resetUrl.pathname.match(/\/reset-password\/[^/?]+$/)?.[0];
    expect(resetPath).toBeTruthy();
    const resetTarget = `${resetPath}${resetUrl.search}`;
    await page.goto(resetTarget);

    // Negative path leaves the token usable so the same browser can recover.
    await page.fill('input[name="password"]', newPassword);
    await page.fill('input[name="password_confirmation"]', `${newPassword}x`);
    await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่' }).click();
    await expect(page.getByText('ยืนยันรหัสผ่านไม่ตรงกัน')).toBeVisible();
    expect(mysqlInt(`SELECT COUNT(*) FROM password_resets WHERE email = ${sqlString(email)}`)).toBe(1);

    await page.fill('input[name="password"]', newPassword);
    await page.fill('input[name="password_confirmation"]', newPassword);
    await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่' }).click();
    await expect(page).toHaveURL(/\/login/);
    await expect(page.getByText('ตั้งรหัสผ่านใหม่เรียบร้อยแล้ว กรุณาเข้าสู่ระบบอีกครั้ง')).toBeVisible();
    expect(mysqlInt(`SELECT COUNT(*) FROM password_resets WHERE email = ${sqlString(email)}`)).toBe(0);

    // A replay of the one-time link is rejected, then login proves the password write reached auth.
    await page.goto(resetTarget);
    await page.fill('input[name="password"]', 'AnotherPass789!');
    await page.fill('input[name="password_confirmation"]', 'AnotherPass789!');
    await page.getByRole('button', { name: 'บันทึกรหัสผ่านใหม่' }).click();
    await expect(page.getByText('ลิงก์รีเซ็ตรหัสผ่านไม่ถูกต้องหรือหมดอายุแล้ว กรุณาขอลิงก์ใหม่อีกครั้ง')).toBeVisible();

    await page.goto('/login');
    await page.fill('input[name="login"]', username);
    await page.fill('input[name="password"]', oldPassword);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
    await expect(page.getByText('ชื่อผู้ใช้ อีเมล หรือรหัสผ่านไม่ถูกต้อง')).toBeVisible();

    await page.fill('input[name="login"]', username);
    await page.fill('input[name="password"]', newPassword);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator('#app-sidebar')).toBeVisible();
  } finally {
    await admin.close();
    await guest.close();
  }
});
