import { test, expect } from '@playwright/test';
import { expectNoMissingIcons } from '../helpers/icons';

// Golden path A — login (happy path).
// This exercises the real session handoff (Session::regenerate() + auth->login()) that the PHP
// unit tests deliberately skip because it can't run under CLI. E2E covers exactly that gap.
test('golden path A — login lands on the dashboard', async ({ page }) => {
  await page.goto('/login');

  await page.fill('input[name="login"]', 'admin');
  await page.fill('input[name="password"]', 'admin12345');
  await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();

  // Post-login: redirected to the dashboard and the authenticated shell is present.
  await expect(page).toHaveURL(/\/dashboard/);
  await expect(page.locator('#app-sidebar')).toBeVisible(); // sidebar only renders when logged in

  // The authenticated shell renders the nav "แจ้งซ่อมใหม่" (plus-circle) + notification bell (alert-circle);
  // fail if either — or any other icon — fell back to the missing-icon placeholder.
  await expectNoMissingIcons(page);
});
