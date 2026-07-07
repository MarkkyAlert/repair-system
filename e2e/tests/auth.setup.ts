import { test as setup, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Logs each role in once through the real login form and saves its storageState, so the
// multi-role lifecycle test can reuse a logged-in context per role without re-logging-in
// for every action. Credentials are the documented seed accounts (flow.md).

const authDir = path.join(__dirname, '..', '.auth');
fs.mkdirSync(authDir, { recursive: true });

const accounts: Record<string, { login: string; password: string }> = {
  requester: { login: 'requester', password: 'requester123' },
  admin: { login: 'admin', password: 'admin12345' },
  technician: { login: 'technician', password: 'tech12345' },
};

for (const [role, creds] of Object.entries(accounts)) {
  setup(`authenticate ${role}`, async ({ page }) => {
    await page.goto('/login');
    await page.fill('input[name="login"]', creds.login);
    await page.fill('input[name="password"]', creds.password);
    await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click();

    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.locator('#app-sidebar')).toBeVisible();

    await page.context().storageState({ path: path.join(authDir, `${role}.json`) });
  });
}
