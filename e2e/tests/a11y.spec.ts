import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import path from 'node:path';

// Accessibility regression net. Runs axe-core against the key authenticated pages and fails on any
// serious/critical WCAG A/AA violation, plus a real-keyboard check of the confirm-modal focus trap
// (F1) that a static PHP guard can't cover. Scoped to serious+critical so best-practice noise doesn't
// make the net flaky — the classes of issue this review fixed (missing <h1>, unlabelled control,
// non-focusable scroll region, ARIA/contrast) surface at those impact levels.

const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

const analyze = (page: import('@playwright/test').Page) =>
  new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

test.describe('accessibility (axe)', () => {
  test.use({ storageState: adminState });

  const pages: Array<{ name: string; url: string }> = [
    { name: 'dashboard', url: '/dashboard' },
    { name: 'tickets list', url: '/tickets' },
    { name: 'ticket create', url: '/tickets/create' },
    { name: 'admin', url: '/admin' },
    { name: 'reports', url: '/reports' },
    { name: 'asset create', url: '/asset-registry/create' },
    // ux-review-2: broadcast (F6 page contrast) + forgot-password (F1 mobile reflow doesn't regress a11y).
    { name: 'broadcast', url: '/admin/broadcast' },
    { name: 'forgot password', url: '/forgot-password' },
    // ux-review-6: notification preferences (F3 event-chip contrast).
    { name: 'notification preferences', url: '/profile/notifications' },
  ];

  for (const p of pages) {
    test(`no serious/critical axe violations: ${p.name}`, async ({ page }) => {
      await page.goto(p.url);
      await page.waitForLoadState('networkidle');
      const results = await analyze(page);
      const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      const summary = blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`).join('\n');
      expect(blocking, `\n${summary}`).toEqual([]);
    });
  }

  // Deep pages behind navigation — the breadcrumb contrast (F5) only appears on detail pages.
  test('no serious/critical axe violations: ticket detail (breadcrumb)', async ({ page }) => {
    await page.goto('/tickets');
    await page.waitForLoadState('networkidle');
    await page.locator('a.ticket-queue-row').first().click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.breadcrumb')).toBeVisible();
    const results = await analyze(page);
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const summary = blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`).join('\n');
    expect(blocking, `\n${summary}`).toEqual([]);
  });

  // ux-review-5 F1: on mobile the status stepper (.workflow-progress) becomes a horizontal scroll region and
  // must be keyboard-focusable (axe scrollable-region-focusable, serious). Desktop doesn't overflow, so this
  // only surfaces at 375px.
  test('no serious/critical axe violations: ticket detail on mobile (stepper scroll region)', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/tickets');
    await page.waitForLoadState('networkidle');
    await page.locator('a.ticket-queue-row').first().click();
    await page.waitForLoadState('networkidle');
    const stepper = page.locator('.workflow-progress');
    await expect(stepper).toBeVisible();
    // It overflows at 375px, so app.js must have made it focusable.
    await expect(stepper).toHaveAttribute('tabindex', '0');
    const results = await analyze(page);
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const summary = blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`).join('\n');
    expect(blocking, `\n${summary}`).toEqual([]);
  });

  // ux-review-6 F2: the report stat rail (.report-stat-scroll) is a horizontal scroll region on mobile and
  // must be keyboard-focusable (axe scrollable-region-focusable, serious).
  test('no serious/critical axe violations: reports on mobile (stat rail scroll region)', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/reports');
    await page.waitForLoadState('networkidle');
    const rail = page.locator('.report-stat-scroll').first();
    await expect(rail).toBeVisible();
    await expect(rail).toHaveAttribute('tabindex', '0');
    const results = await analyze(page);
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const summary = blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`).join('\n');
    expect(blocking, `\n${summary}`).toEqual([]);
  });

  // The confirm-modal summary (<dt> labels, lead) uses muted text (F6 modal state).
  test('no serious/critical axe violations: broadcast confirm modal open', async ({ page }) => {
    await page.goto('/admin/broadcast');
    await page.fill('#broadcast_title', 'a11y contrast');
    await page.fill('#broadcast_message', 'confirm modal contrast check');
    await page.click('[data-confirm-modal-trigger="broadcast-confirm-modal"]');
    await expect(page.locator('#broadcast-confirm-modal')).toBeVisible();
    const results = await analyze(page);
    const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
    const summary = blocking.map((v) => `${v.id} (${v.impact}) x${v.nodes.length}: ${v.nodes[0]?.target?.join(' ')}`).join('\n');
    expect(blocking, `\n${summary}`).toEqual([]);
  });
});

test.describe('keyboard: confirm-modal focus trap (F1)', () => {
  test.use({ storageState: adminState });

  test('Tab never escapes the open confirm modal, Esc closes it', async ({ page }) => {
    await page.goto('/admin/broadcast');
    await page.fill('#broadcast_title', 'a11y e2e');
    await page.fill('#broadcast_message', 'focus trap check');
    await page.click('[data-confirm-modal-trigger="broadcast-confirm-modal"]');

    const modal = page.locator('#broadcast-confirm-modal');
    await expect(modal).toBeVisible();

    // Focus lands inside on open; cycle Tab/Shift+Tab and confirm it stays trapped.
    for (let i = 0; i < 6; i++) {
      await page.keyboard.press('Tab');
      expect(await modal.evaluate((m) => m.contains(document.activeElement))).toBe(true);
    }
    for (let i = 0; i < 3; i++) {
      await page.keyboard.press('Shift+Tab');
      expect(await modal.evaluate((m) => m.contains(document.activeElement))).toBe(true);
    }

    await page.keyboard.press('Escape');
    await expect(modal).toBeHidden();
  });
});

test.describe('keyboard: mobile sidebar drawer focus trap (F1b)', () => {
  test.use({ storageState: adminState, viewport: { width: 375, height: 812 } });

  test('opening the drawer moves + traps focus, Esc returns it to the toggle', async ({ page }) => {
    await page.goto('/dashboard');
    const toggle = page.locator('[data-sidebar-toggle]');
    const sidebar = page.locator('#app-sidebar');

    await toggle.click();
    await expect(sidebar).toHaveClass(/is-open/);
    // Focus moves into the nav on open, and Tab stays trapped inside the drawer.
    expect(await sidebar.evaluate((s) => s.contains(document.activeElement))).toBe(true);
    for (let i = 0; i < 8; i++) {
      await page.keyboard.press('Tab');
      expect(await sidebar.evaluate((s) => s.contains(document.activeElement))).toBe(true);
    }

    // Esc closes the drawer and returns focus to the toggle.
    await page.keyboard.press('Escape');
    await expect(sidebar).not.toHaveClass(/is-open/);
    expect(await toggle.evaluate((t) => t === document.activeElement)).toBe(true);
  });
});

test.describe('keyboard: admin tabs roving tabindex + arrow nav (a11y-review F3)', () => {
  test.use({ storageState: adminState });

  test('ArrowRight moves selection to the next tab; only the selected tab is a Tab stop', async ({ page }) => {
    await page.goto('/admin');
    const tabs = page.locator('.admin-tab');

    // Roving tabindex: exactly one tab (the selected one) is in the Tab order.
    const zeroTabindex = await tabs.evaluateAll((els) =>
      els.filter((e) => e.getAttribute('tabindex') === '0').length);
    expect(zeroTabindex).toBe(1);
    const firstId = await tabs.first().getAttribute('aria-controls');

    // Focus the selected tab, then arrow to the next one — focus + selection move together.
    await tabs.first().focus();
    await page.keyboard.press('ArrowRight');
    const second = tabs.nth(1);
    expect(await second.evaluate((t) => t === document.activeElement)).toBe(true);
    await expect(second).toHaveAttribute('aria-selected', 'true');
    await expect(second).toHaveAttribute('tabindex', '0');
    // The previously-selected tab drops out of the Tab order.
    await expect(tabs.first()).toHaveAttribute('tabindex', '-1');
    expect(await second.getAttribute('aria-controls')).not.toBe(firstId);

    // Arrow back returns to the first tab.
    await page.keyboard.press('ArrowLeft');
    expect(await tabs.first().evaluate((t) => t === document.activeElement)).toBe(true);
    await expect(tabs.first()).toHaveAttribute('aria-selected', 'true');
  });
});

test.describe('keyboard: notification popover focus + valid role (ux-review-6 F4)', () => {
  test.use({ storageState: adminState });

  test('opening the bell moves focus into the dialog; Escape returns it; no aria-allowed-role', async ({ page }) => {
    await page.goto('/dashboard');
    const toggle = page.locator('[data-notification-toggle]');
    const menu = page.locator('[data-notification-menu]');

    await toggle.click();
    await expect(menu).toBeVisible();
    // Focus lands inside the popover (not stranded on the bell).
    expect(await menu.evaluate((m) => m.contains(document.activeElement))).toBe(true);

    // The dialog is a <div>, not an <aside> (aria-allowed-role): no such violation.
    const results = await new AxeBuilder({ page }).include('[data-notification-menu]').analyze();
    expect(results.violations.filter((v) => v.id === 'aria-allowed-role')).toEqual([]);

    // Escape closes and returns focus to the bell.
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    expect(await toggle.evaluate((t) => t === document.activeElement)).toBe(true);
  });
});

test.describe('auth: invalid login is announced + wired (ux-review-6 F5)', () => {
  test('a failed login renders a focused role=alert linked to the fields', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#login', 'nobody');
    await page.fill('#password', 'wrongpassword');
    await page.locator('form button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const alert = page.locator('[data-auth-error]');
    await expect(alert).toBeVisible();
    await expect(alert).toHaveAttribute('role', 'alert');
    // app.js moves focus to the error so it is announced after the reload.
    expect(await alert.evaluate((a) => a === document.activeElement)).toBe(true);
    // The fields point back to the error summary.
    await expect(page.locator('#login')).toHaveAttribute('aria-describedby', 'auth-error');
    await expect(page.locator('#login')).toHaveAttribute('aria-invalid', 'true');
  });
});
