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
