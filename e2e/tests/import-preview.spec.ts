import { test, expect } from '@playwright/test';
import path from 'node:path';
import { mysqlRows, sqlString } from '../helpers/db';

// F3 (logic review) — CSV import preview is scoped to a one-time token, so opening a second preview in
// another tab cannot make the first tab's "confirm" import the second tab's rows. Two pages share ONE admin
// context (one session cookie), which is exactly the multi-tab hazard: previewing B replaces the session
// batch, so confirming the STALE tab A must be refused (its token no longer matches) — nothing is imported.
//
// Both paths run in CI: the stale-tab guard and one successful import through login.

const authDir = path.join(__dirname, '..', '.auth');
const adminState = path.join(authDir, 'admin.json');

function userCsv(username: string, password = ''): { name: string; mimeType: string; buffer: Buffer } {
  // columns: username,email,full_name,role,department_code,phone,password
  const csv =
    'username,email,full_name,role,department_code,phone,password\n' +
    `${username},${username}@e2e.test,${username} Name,requester,,,${password}\n`;
  return { name: `${username}.csv`, mimeType: 'text/csv', buffer: Buffer.from(csv, 'utf-8') };
}

test('CSV import: preview → confirm → DB row → imported user can log in', async ({ browser }) => {
  const admin = await browser.newContext({ storageState: adminState });
  const username = `e2ei${Date.now()}`;
  const password = 'E2ePass123!';

  try {
    const page = await admin.newPage();
    await page.goto('/admin/users/import');
    await page.setInputFiles('input[name="csv"]', userCsv(username, password));
    await page.getByRole('button', { name: 'อัปโหลดและตรวจสอบ' }).click();
    await expect(page.getByText(`${username}@e2e.test`)).toBeVisible();

    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: /ยืนยันนำเข้า 1 ผู้ใช้/ }).click();
    await expect(page).toHaveURL(/\/admin/);
    await expect(page.getByText(/นำเข้า 1 ผู้ใช้/)).toBeVisible();

    expect(
      mysqlRows(
        `SELECT username, email, role, is_active FROM users WHERE username = ${sqlString(username)}`
      )
    ).toEqual([[username, `${username}@e2e.test`, 'requester', '1']]);

    const importedUser = await browser.newContext();
    try {
      const login = await importedUser.newPage();
      await login.goto('/login');
      await login.fill('input[name="login"]', username);
      await login.fill('input[name="password"]', password);
      await login.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();
      await expect(login).toHaveURL(/\/dashboard/);
      await expect(login.locator('#app-sidebar')).toBeVisible();
    } finally {
      await importedUser.close();
    }
  } finally {
    await admin.close();
  }
});

test('CSV import: confirming a stale preview tab does not import another tab\'s rows (F3)', async ({ browser }) => {
  const admin = await browser.newContext({ storageState: adminState });
  const ts = Date.now();
  const userA = `e2ea${ts}`;
  const userB = `e2eb${ts}`;

  try {
    // Tab A: preview batch A
    const pageA = await admin.newPage();
    await pageA.goto('/admin/users/import');
    await pageA.setInputFiles('input[name="csv"]', userCsv(userA));
    await pageA.getByRole('button', { name: 'อัปโหลดและตรวจสอบ' }).click();
    await expect(pageA.getByRole('button', { name: /ยืนยันนำเข้า/ })).toBeVisible();

    // Tab B (same session): preview batch B — this replaces the session batch + token
    const pageB = await admin.newPage();
    await pageB.goto('/admin/users/import');
    await pageB.setInputFiles('input[name="csv"]', userCsv(userB));
    await pageB.getByRole('button', { name: 'อัปโหลดและตรวจสอบ' }).click();
    await expect(pageB.getByRole('button', { name: /ยืนยันนำเข้า/ })).toBeVisible();

    // Tab A confirms its now-stale batch (auto-accept the JS confirm dialog)
    pageA.on('dialog', (d) => d.accept());
    await pageA.getByRole('button', { name: /ยืนยันนำเข้า/ }).click();

    // The stale confirm is refused — the mismatch message is shown and nothing is imported
    await expect(pageA.getByText(/การยืนยันนำเข้าไม่ตรงกับไฟล์/)).toBeVisible();

    // Neither user exists (A refused; B never confirmed). The admin user search proves it.
    const check = await admin.newPage();
    await check.goto(`/admin?user_search=${userA}`);
    await expect(check.getByText(`${userA}@e2e.test`)).toHaveCount(0);
    await check.goto(`/admin?user_search=${userB}`);
    await expect(check.getByText(`${userB}@e2e.test`)).toHaveCount(0);
  } finally {
    await admin.close();
  }
});
