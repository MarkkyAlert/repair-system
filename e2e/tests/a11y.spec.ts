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
