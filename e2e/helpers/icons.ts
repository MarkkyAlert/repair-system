import { expect, type Page } from '@playwright/test';

// Guard against the lucide icon bug E2E originally surfaced: when a template asks for an icon name that is
// absent from app/Helpers/icons.php, the helper logs "[lucide] missing icon: <name>" server-side AND renders
// a red "missing" fallback SVG carrying data-missing-icon="<name>". Asserting no such element exists on a page
// catches any future template that uses an unmapped icon name — the DOM signal is stronger than the log line.
export async function expectNoMissingIcons(page: Page): Promise<void> {
  const missing = page.locator('[data-missing-icon]');
  const count = await missing.count();
  if (count > 0) {
    const names = await missing.evaluateAll((els) =>
      els.map((el) => el.getAttribute('data-missing-icon')),
    );
    throw new Error(`lucide missing-icon fallback rendered for: ${[...new Set(names)].join(', ')}`);
  }
  await expect(missing).toHaveCount(0);
}
