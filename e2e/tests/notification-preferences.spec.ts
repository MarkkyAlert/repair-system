import { expect, test } from '@playwright/test';
import path from 'node:path';
import { mysqlExec, mysqlInt, mysqlRows, sqlString } from '../helpers/db';

const requesterState = path.join(__dirname, '..', '.auth', 'requester.json');

function restorePreferences(userId: number, rows: string[][]): void {
  mysqlExec(`DELETE FROM notification_preferences WHERE user_id = ${userId}`);
  for (const [type, channel, enabled] of rows) {
    mysqlExec(
      `INSERT INTO notification_preferences (user_id, notification_type, channel, is_enabled) VALUES (` +
        `${userId}, ${sqlString(type)}, ${sqlString(channel)}, ${Number(enabled)})`
    );
  }
}

test('notification preferences: UI save reaches DB and survives reload', async ({ browser }) => {
  const requester = await browser.newContext({ storageState: requesterState });
  const userId = mysqlInt("SELECT id FROM users WHERE username = 'requester'");
  const before = mysqlRows(
    `SELECT notification_type, channel, is_enabled FROM notification_preferences ` +
      `WHERE user_id = ${userId} ORDER BY notification_type, channel`
  );

  try {
    const page = await requester.newPage();
    await page.goto('/profile/notifications');
    const emailToggle = page.locator('#pref_comment_added_email');
    const original = await emailToggle.isChecked();
    const expected = !original;

    await emailToggle.setChecked(expected);
    await page.getByRole('button', { name: 'บันทึกการตั้งค่า' }).click();
    await expect(page.getByText('บันทึกการตั้งค่าการแจ้งเตือนเรียบร้อยแล้ว')).toBeVisible();

    expect(
      mysqlInt(
        `SELECT is_enabled FROM notification_preferences WHERE user_id = ${userId} ` +
          `AND notification_type = 'comment_added' AND channel = 'email'`
      )
    ).toBe(expected ? 1 : 0);

    await page.reload();
    await expect(page.locator('#pref_comment_added_email')).toBeChecked({ checked: expected });
  } finally {
    restorePreferences(userId, before);
    await requester.close();
  }
});
