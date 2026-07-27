import { expect, test } from '@playwright/test';
import path from 'node:path';

// Adversarial UI-breakage regression net (2026-07-27).
//
// The suite had exactly one viewport (Desktop Chrome), so nothing ever rendered the app narrow. A survey of
// 132 route-visits (every UI route x guest/admin/technician/requester x 375/768/1440) found the layout clean
// everywhere EXCEPT the ticket queue between 701px and 799px — iPad-portrait territory.
//
// Why that band: the <=1024px rule gives the queue grid hard column minimums
//   minmax(220px,…) minmax(100px,…) minmax(110px,…) minmax(150px,…) minmax(110px,…)  = 690px
// plus gaps and row padding, while the stacked mobile layout only starts at <=700px. So from 701 to ~799 the
// row is wider than the page, the last column is cut off, AND documentElement does not scroll — the clipped
// text is simply unreachable rather than scrollable.
//
// The assertion deliberately checks "clipped with no way to reach it" rather than the raw scrollWidth, because
// a horizontally scrollable table is a legitimate design choice; content that is cut off and unscrollable is not.

const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

// 701 is the first pixel above the stacked-layout breakpoint (the worst case), 768 is iPad portrait.
// 799 was tried and dropped: it already fitted before the fix, so it discriminated nothing.
for (const width of [701, 720, 768]) {
  test(`ticket queue: no row content is clipped out of reach at ${width}px`, async ({ browser }) => {
    const context = await browser.newContext({ storageState: adminState, viewport: { width, height: 900 } });
    try {
      const page = await context.newPage();
      await page.goto('/tickets', { waitUntil: 'networkidle' });
      await expect(page.locator('.ticket-queue-row').first()).toBeVisible();

      const verdict = await page.evaluate((vw) => {
        const de = document.documentElement;
        const pageScrolls = de.scrollWidth > de.clientWidth + 1;
        let worst = 0;
        let culprit = '';
        for (const el of document.querySelectorAll('.ticket-queue-row, .ticket-queue-row *')) {
          const cs = getComputedStyle(el);
          if (cs.display === 'none' || cs.visibility === 'hidden') continue;
          const rect = el.getBoundingClientRect();
          if (rect.width === 0 || rect.height === 0) continue;
          const over = rect.right - vw;
          if (over > worst) {
            worst = over;
            culprit = `${el.tagName.toLowerCase()}.${(el.className || '').toString().slice(0, 40)}`;
          }
        }
        return { pageScrolls, worst: Math.round(worst), culprit };
      }, width);

      // clipped AND unscrollable = the reader can never see it
      expect(
        verdict.worst,
        `queue content overflows ${verdict.worst}px past the ${width}px viewport (${verdict.culprit}); `
          + `page horizontally scrollable: ${verdict.pageScrolls} — so the cut-off text is unreachable`
      ).toBeLessThanOrEqual(1);
    } finally {
      await context.close();
    }
  });
}

// Guardrail on the fix itself: shrinking the columns must not collapse the queue into an unreadable sliver.
// Without this, "no overflow" could be satisfied by squashing every column to nothing.
test('ticket queue: the title column stays readable while fitting at 768px', async ({ browser }) => {
  const context = await browser.newContext({ storageState: adminState, viewport: { width: 768, height: 900 } });
  try {
    const page = await context.newPage();
    await page.goto('/tickets', { waitUntil: 'networkidle' });
    const mainWidth = await page.locator('.ticket-queue-row .ticket-queue-main').first().evaluate(
      (el) => Math.round(el.getBoundingClientRect().width)
    );
    expect(mainWidth, 'the ticket title column must keep a usable share of the row').toBeGreaterThan(180);
  } finally {
    await context.close();
  }
});
