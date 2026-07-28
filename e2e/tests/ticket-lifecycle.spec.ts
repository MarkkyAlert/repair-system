import { test, expect } from '@playwright/test';
import path from 'node:path';
import { mysqlInt, mysqlRows, mysqlValue } from '../helpers/db';
import { expectNoMissingIcons } from '../helpers/icons';

// Golden path C — one full ticket lifecycle through the real UI, across roles:
// requester creates → admin approves + assigns → technician accepts + starts + resolves →
// requester completes + rates. Each role runs in its own context loaded from the storageState
// saved by auth.setup.ts (login once, reuse across that role's actions).
//
// Status transitions are asserted via stable, style-independent signals: the per-status action
// forms (#action-assign / #action-start / #action-resolve / #action-complete appear only in
// their status) plus the Thai status badge text. No CSS-class selectors, no data-testid needed.

const authDir = path.join(__dirname, '..', '.auth');
const stateFor = (role: string) => path.join(authDir, `${role}.json`);

test('golden path C — full ticket lifecycle across roles', async ({ browser }) => {
  const requester = await browser.newContext({ storageState: stateFor('requester') });
  const admin = await browser.newContext({ storageState: stateFor('admin') });
  const technician = await browser.newContext({ storageState: stateFor('technician') });

  const title = `E2E-C-${Date.now()} lifecycle`;

  try {
    // 1) requester creates the ticket ------------------------------------------------
    const rp = await requester.newPage();
    await rp.goto('/tickets/create');
    await expectNoMissingIcons(rp); // shell (plus-circle, alert-circle) on the create page
    await rp.fill('input[name="title"]', title);
    await rp.fill('textarea[name="description"]', 'E2E automated lifecycle — safe to delete.');
    await rp.selectOption('select[name="priority_id"]', { index: 1 });
    await rp.selectOption('select[name="ticket_category_id"]', { index: 1 });
    await rp.selectOption('select[name="location_id"]', { index: 1 });
    await rp.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).click();

    await expect(rp).toHaveURL(/\/tickets\/\d+/);
    const ticketId = rp.url().match(/\/tickets\/(\d+)/)![1];
    await expect(rp.getByText('รออนุมัติ').first()).toBeVisible(); // pending_approval
    expect(
      mysqlValue(
        `SELECT CONCAT(t.status, '|', u.username) FROM tickets t ` +
          `INNER JOIN users u ON u.id = t.requester_id WHERE t.id = ${ticketId}`
      )
    ).toBe('pending_approval|requester');

    // 2) admin approves --------------------------------------------------------------
    const ap = await admin.newPage();
    await ap.goto(`/tickets/${ticketId}`);
    await ap.fill('textarea[name="note"]', 'E2E approve');
    await ap.getByRole('button', { name: 'อนุมัติ', exact: true }).click();
    await expect(ap.locator('#action-assign')).toBeVisible(); // approved → assign form appears

    // 3) admin assigns the technician ------------------------------------------------
    const technicianId = mysqlValue("SELECT id FROM users WHERE username = 'technician'");
    await ap.selectOption('#action-assign select[name="technician_id"]', technicianId);
    await ap.fill('#action-assign textarea[name="instructions"]', 'E2E assign');
    await ap.getByRole('button', { name: 'มอบหมายงานให้ช่าง' }).click();
    await expect(ap.getByText('มอบหมายแล้ว').first()).toBeVisible(); // assigned

    // 4) technician accepts → starts → resolves --------------------------------------
    const tp = await technician.newPage();
    await tp.goto(`/tickets/${ticketId}`);
    await tp.getByRole('button', { name: 'ยืนยันรับงาน' }).click();
    await expect(tp.locator('#action-start')).toBeVisible(); // accepted

    await tp.getByRole('button', { name: 'เริ่มงาน' }).click();
    await expect(tp.locator('#action-resolve')).toBeVisible(); // in_progress

    await tp.fill('#action-resolve textarea[name="diagnosis_summary"]', 'E2E diagnosis');
    await tp.fill('#action-resolve textarea[name="resolution_summary"]', 'E2E resolution');
    await tp.fill('#action-resolve input[name="labor_minutes"]', '30');
    await tp.getByRole('button', { name: 'สรุปงาน' }).click();
    await expect(tp.getByText('รอตรวจรับ').first()).toBeVisible(); // resolved

    // 5) requester confirms + rates --------------------------------------------------
    const rp2 = await requester.newPage();
    await rp2.goto(`/tickets/${ticketId}`);
    await expectNoMissingIcons(rp2); // resolved ticket: requester sees complete + reopen (rotate-ccw) actions
    await rp2.locator('#action-complete label[for="star5"]').click(); // custom star widget: radios are hidden, click the label
    await rp2.fill('#action-complete textarea[name="closure_note"]', 'E2E closure');
    await rp2.fill('#action-complete textarea[name="feedback"]', 'E2E feedback');
    await rp2.getByRole('button', { name: 'ยืนยันปิดงานและส่งคะแนน' }).click();
    await expect(rp2.getByText('เสร็จสิ้น').first()).toBeVisible(); // completed

    // Destination evidence: UI state survives reload and every material write reached the real DB.
    await rp2.reload();
    await expect(rp2.getByText('เสร็จสิ้น').first()).toBeVisible();
    await expect(rp2.getByText('E2E closure', { exact: true })).toBeVisible();
    await expect(rp2.getByText('E2E feedback', { exact: true })).toBeVisible();

    expect(mysqlRows(`SELECT status, closure_note FROM tickets WHERE id = ${ticketId}`)).toEqual([
      ['completed', 'E2E closure'],
    ]);
    expect(
      mysqlRows(
        `SELECT status, labor_minutes, diagnosis_summary, resolution_summary ` +
          `FROM work_orders WHERE ticket_id = ${ticketId}`
      )
    ).toEqual([['completed', '30', 'E2E diagnosis', 'E2E resolution']]);
    expect(mysqlRows(`SELECT score, feedback FROM ticket_ratings WHERE ticket_id = ${ticketId}`)).toEqual([
      ['5', 'E2E feedback'],
    ]);

    const actions = mysqlRows(
      `SELECT action FROM ticket_activity_logs WHERE ticket_id = ${ticketId} ORDER BY id`
    ).map(([action]) => action);
    expect(actions).toEqual(
      expect.arrayContaining([
        'ticket_submitted',
        'ticket_approved',
        'technician_assigned',
        'work_accepted',
        'work_started',
        'ticket_resolved',
        'ticket_completed',
      ])
    );
    expect(
      mysqlInt(`SELECT COUNT(*) FROM notifications WHERE related_type = 'ticket' AND related_id = ${ticketId}`)
    ).toBeGreaterThan(0);
  } finally {
    await requester.close();
    await admin.close();
    await technician.close();
  }
});
