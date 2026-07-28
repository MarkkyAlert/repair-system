import { expect, Page, test } from '@playwright/test';
import path from 'node:path';
import { mysqlRows, mysqlValue, sqlString } from '../helpers/db';

const authDir = path.join(__dirname, '..', '.auth');
const adminState = path.join(authDir, 'admin.json');
const requesterState = path.join(authDir, 'requester.json');

async function editForm(page: Page, username: string) {
  const usernameField = page.locator(`input[disabled][value="${username}"]`);
  await expect(usernameField).toHaveCount(1);
  const panel = usernameField.locator('xpath=ancestor::details[1]');
  if ((await panel.getAttribute('open')) === null) {
    await panel.locator('summary').first().click();
  }
  await expect(usernameField).toBeVisible();
  return usernameField.locator('xpath=ancestor::form[1]');
}

test('admin user CRUD: create persists, and a stale second tab cannot overwrite the first', async ({ browser }) => {
  const admin = await browser.newContext({ storageState: adminState });
  const stamp = Date.now();
  const username = `e2eu${stamp}`;
  const email = `${username}@e2e.test`;

  try {
    const createPage = await admin.newPage();
    await createPage.goto('/admin#tab-users');
    const createPanel = createPage.locator('details', { hasText: 'สร้างบัญชีผู้ใช้งาน' }).first();
    await createPanel.locator('summary').click();
    await createPanel.locator('#new_username').fill(username);
    await createPanel.locator('#new_full_name').fill(`E2E User ${stamp}`);
    await createPanel.locator('#new_email').fill(email);
    await createPanel.locator('#new_password').fill('E2ePass123!');
    await createPanel.locator('#new_password_confirmation').fill('E2ePass123!');
    await createPanel.getByRole('button', { name: 'สร้างบัญชีผู้ใช้งาน' }).click();

    await expect(createPage.getByText('สร้างบัญชีผู้ใช้งานเรียบร้อยแล้ว')).toBeVisible();
    const userId = mysqlValue(`SELECT id FROM users WHERE username = ${sqlString(username)}`);
    expect(userId).toMatch(/^\d+$/);
    expect(
      mysqlRows(
        `SELECT username, email, role, is_active, version FROM users WHERE id = ${Number(userId)}`
      )
    ).toEqual([[username, email, 'requester', '1', '1']]);

    // Both tabs render version=1 before either writes.
    const pageA = await admin.newPage();
    const pageB = await admin.newPage();
    await Promise.all([pageA.goto('/admin#tab-users'), pageB.goto('/admin#tab-users')]);
    const formA = await editForm(pageA, username);
    const formB = await editForm(pageB, username);

    await formA.locator('input[name="full_name"]').fill(`E2E User ${stamp} A`);
    await formA.getByRole('button', { name: 'บันทึกการเปลี่ยนแปลง' }).click();
    await expect(pageA.getByText('บันทึกข้อมูลเรียบร้อยแล้ว')).toBeVisible();

    await formB.locator('input[name="full_name"]').fill(`E2E User ${stamp} B`);
    await formB.getByRole('button', { name: 'บันทึกการเปลี่ยนแปลง' }).click();
    await expect(
      pageB.getByText('ข้อมูลผู้ใช้ถูกแก้ไขโดยผู้ใช้อื่นแล้ว กรุณารีเฟรชหน้าแล้วลองอีกครั้ง')
    ).toBeVisible();

    expect(mysqlRows(`SELECT full_name, version FROM users WHERE id = ${Number(userId)}`)).toEqual([
      [`E2E User ${stamp} A`, '2'],
    ]);
  } finally {
    await admin.close();
  }
});

test('admin user CRUD: requester cannot open the admin surface', async ({ browser }) => {
  const requester = await browser.newContext({ storageState: requesterState });
  try {
    const page = await requester.newPage();
    const response = await page.goto('/admin');
    expect(response?.status()).toBe(403);
    await expect(page).toHaveURL(/\/admin/);
    await expect(page.getByText('หน้านี้สงวนสำหรับผู้ดูแลระบบเท่านั้น')).toBeVisible();
    await expect(page.getByText('สร้างบัญชีผู้ใช้งาน')).toHaveCount(0);
  } finally {
    await requester.close();
  }
});
