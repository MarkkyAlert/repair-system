import { expect, test } from '@playwright/test';
import path from 'node:path';

// Vertical-reach net for phones (2026-08-16).
//
// Every existing net measures the horizontal axis — overflow, clipped columns, unclickable controls. Nothing
// measured how far DOWN the page the thing you came for sits, and there is no screenshot diffing anywhere in
// the repo, so chrome could grow above the fold indefinitely without a single test noticing. It had:
//
//   /login    the username field started at y=736 on a 375x812 phone — the form was below the fold, and a
//             failed attempt pushed it a further ~60px away from the field you needed to retype.
//   /tickets  the first work row started at y=1364 — about 1.9 screens of summary cards, alert strip and
//             filters before the work itself.
//
// The thresholds below are set so they would FAIL on the pre-fix layout by a wide margin. Note that the naive
// form of the login assertion — "usernameTop < viewport height" — would have PASSED at 736 and proved nothing;
// what matters is that the whole form is usable without scrolling, so the submit button is the real anchor.

const MOBILE = { width: 375, height: 812 };
const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

/** Web fonts change every text height; measuring before they settle is the main source of jitter here. */
async function settle(page: import('@playwright/test').Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
}

test('mobile: the whole login form is usable without scrolling', async ({ browser }) => {
  const context = await browser.newContext({ viewport: MOBILE });
  try {
    const page = await context.newPage();
    await page.goto('/login', { waitUntil: 'networkidle' });
    await settle(page);

    const box = await page.evaluate(() => {
      const y = (el: Element | null) => (el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : -1);
      const bottom = (el: Element | null) =>
        el ? Math.round(el.getBoundingClientRect().bottom + window.scrollY) : -1;
      return {
        username: y(document.getElementById('login')),
        submit: bottom(document.querySelector('form.auth-card button[type="submit"]')),
      };
    });

    expect(box.username, `username field sits ${box.username}px down (was 736 before the fix)`).toBeLessThanOrEqual(400);
    expect(
      box.submit,
      `the sign-in button ends at ${box.submit}px on a ${MOBILE.height}px screen — the form must fit without scrolling`
    ).toBeLessThanOrEqual(MOBILE.height);
  } finally {
    await context.close();
  }
});

test('mobile: a failed sign-in does not push the form off the screen', async ({ browser }) => {
  // The error alert is injected rather than produced by posting bad credentials on purpose: login is rate
  // limited per account AND per IP, login.spec.ts already spends a failed attempt against the same seeded DB
  // in the same serial run, and a tripped limiter changes both the message and its height. This measures the
  // layout consequence of the alert, which is the thing under test.
  const context = await browser.newContext({ viewport: MOBILE });
  try {
    const page = await context.newPage();
    await page.goto('/login', { waitUntil: 'networkidle' });
    await settle(page);

    await page.evaluate(() => {
      const card = document.querySelector('form.auth-card');
      const header = card?.querySelector('.auth-card-header');
      if (!card || !header) throw new Error('login form shape changed — update this test');
      const alert = document.createElement('div');
      alert.className = 'auth-alert auth-alert-danger';
      alert.setAttribute('role', 'alert');
      alert.innerHTML = '<p>ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง</p>';
      header.insertAdjacentElement('afterend', alert);
    });
    await settle(page);

    const submitBottom = await page.evaluate(() => {
      const el = document.querySelector('form.auth-card button[type="submit"]');
      return el ? Math.round(el.getBoundingClientRect().bottom + window.scrollY) : -1;
    });

    expect(
      submitBottom,
      `with an error shown the sign-in button ends at ${submitBottom}px — retrying must not require scrolling`
    ).toBeLessThanOrEqual(MOBILE.height);
  } finally {
    await context.close();
  }
});

test('desktop: the login marketing panel stays on the left', async ({ browser }) => {
  // Catches the mobile reorder escaping its media query — the flagship page every buyer opens first.
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  try {
    const page = await context.newPage();
    await page.goto('/login', { waitUntil: 'networkidle' });
    await settle(page);

    const sides = await page.evaluate(() => ({
      copy: Math.round(document.querySelector('.hero-copy')!.getBoundingClientRect().left),
      form: Math.round(document.querySelector('form.auth-card')!.getBoundingClientRect().left),
    }));

    expect(sides.copy, 'on a wide screen the marketing half must stay left of the form').toBeLessThan(sides.form);
  } finally {
    await context.close();
  }
});

for (const route of ['/forgot-password', '/track']) {
  test(`mobile: ${route} keeps its intro above the form`, async ({ browser }) => {
    // The reorder is deliberately scoped to /login. These pages share the same hero markup, and their intro
    // half carries context the form depends on, so they must NOT flip. This is the containment check.
    const context = await browser.newContext({ viewport: MOBILE });
    try {
      const page = await context.newPage();
      await page.goto(route, { waitUntil: 'networkidle' });
      await settle(page);

      const order = await page.evaluate(() => {
        const y = (sel: string) => {
          const el = document.querySelector(sel);
          return el ? Math.round(el.getBoundingClientRect().top + window.scrollY) : -1;
        };
        return { copy: y('.hero-copy'), form: y('.auth-card') };
      });

      expect(order.copy, `${order.copy} vs ${order.form}`).toBeGreaterThanOrEqual(0);
      expect(order.form, 'this page has a form half').toBeGreaterThan(0);
      expect(order.copy, 'the intro must still come first here').toBeLessThan(order.form);
    } finally {
      await context.close();
    }
  });
}

test('mobile: the ticket queue reaches actual work without two screens of chrome', async ({ browser }) => {
  const context = await browser.newContext({ storageState: adminState, viewport: MOBILE });
  try {
    const page = await context.newPage();
    // Bare /tickets on purpose: every other block above the first row (filter chips, the clear button, the
    // expanded advanced panel, the bulk toolbar) renders only when a filter is active, so a query string
    // would make this measurement depend on the URL rather than on the layout.
    await page.goto('/tickets', { waitUntil: 'networkidle' });
    await expect(page.locator('.ticket-queue-row').first()).toBeVisible();
    await settle(page);

    const measured = await page.evaluate(() => {
      const strip = document.querySelector('.operations-alert-strip');
      // The alert strip only exists when something is overdue or waiting, and its height grows with the
      // number of chips. Subtract it (plus the 1.4rem stack gap it consumes) so the number describes the
      // page chrome rather than today's test data.
      const stripCost = strip ? Math.round(strip.getBoundingClientRect().height + 22.4) : 0;
      const row = document.querySelector('.ticket-queue-row')!;
      const rowTop = Math.round(row.getBoundingClientRect().top + window.scrollY);
      return { rowTop, stripCost, cards: document.querySelectorAll('.stat-grid .metric-card').length };
    });

    const normalized = measured.rowTop - measured.stripCost;
    expect(
      normalized,
      `first work row at ${measured.rowTop}px (${normalized}px excluding the ${measured.stripCost}px alert strip) — was 1147px normalized before the fix`
    ).toBeLessThanOrEqual(1000);

    // The four summary cards are also the queue's only one-tap filters. Turning them into a horizontal rail
    // must not put any of them out of reach — the last one (เกินกำหนด) is the one that starts off-screen.
    expect(measured.cards, 'all four filter cards are still rendered').toBe(4);
    const overdueCard = page.locator('.stat-grid .metric-card[href*="sla=overdue"]');
    await overdueCard.scrollIntoViewIfNeeded();
    await expect(overdueCard).toBeVisible();
    await overdueCard.click({ trial: true, timeout: 2_000 });
  } finally {
    await context.close();
  }
});
