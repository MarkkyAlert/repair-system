import { expect, test } from '@playwright/test';
import path from 'node:path';
import { mysqlInt, mysqlValue, sqlString } from '../helpers/db';

const requesterState = path.join(__dirname, '..', '.auth', 'requester.json');
const managerState = path.join(__dirname, '..', '.auth', 'manager.json');
const adminState = path.join(__dirname, '..', '.auth', 'admin.json');

async function fillRequiredTicketFields(page: import('@playwright/test').Page): Promise<void> {
  await page.selectOption('select[name="priority_id"]', { index: 1 });
  await page.selectOption('select[name="ticket_category_id"]', { index: 1 });
  await page.selectOption('select[name="location_id"]', { index: 1 });
}

async function pageOverflow(page: import('@playwright/test').Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test('long Thai + HTML-like text stays readable, escaped, and one double-click creates one ticket', async ({
  browser,
}) => {
  const context = await browser.newContext({
    storageState: requesterState,
    viewport: { width: 375, height: 812 },
  });
  const prefix = `E2E-UI-LONG-${Date.now()}-`;
  const title = prefix + 'ก'.repeat(200 - prefix.length);
  const description = 'E2E-UI-XSS <script>window.__uiAuditXss = true</script>'
    + '<img src=x onerror="window.__uiAuditXss = true">';

  try {
    const page = await context.newPage();
    const consoleErrors: string[] = [];
    let createRequests = 0;
    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('request', (request) => {
      const requestUrl = new URL(request.url());
      if (request.method() === 'POST' && requestUrl.pathname === '/tickets') {
        createRequests += 1;
      }
    });

    await page.goto('/tickets/create');
    await page.fill('input[name="title"]', title);
    await page.fill('textarea[name="description"]', description);
    await fillRequiredTicketFields(page);

    await page.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).evaluate((button: HTMLButtonElement) => {
      button.click();
      button.click();
    });
    await expect(page).toHaveURL(/\/tickets\/\d+$/);

    const ticketId = page.url().match(/\/tickets\/(\d+)$/)?.[1] ?? '';
    expect(ticketId).not.toBe('');
    expect(createRequests, 'a rapid double click must emit one create request').toBe(1);
    expect(
      mysqlInt(`SELECT COUNT(*) FROM tickets WHERE title = ${sqlString(title)}`),
      'one user action must create exactly one ticket row'
    ).toBe(1);

    await expect(page.getByText(description, { exact: true })).toBeVisible();
    expect(await page.evaluate(() => Boolean((window as typeof window & { __uiAuditXss?: boolean }).__uiAuditXss)))
      .toBe(false);
    expect(await page.locator('script').filter({ hasText: 'uiAuditXss' }).count()).toBe(0);
    expect(await page.locator('img[src="x"]').count()).toBe(0);

    for (const viewport of [
      { width: 375, height: 812 },
      { width: 768, height: 1024 },
      { width: 1440, height: 1000 },
    ]) {
      await page.setViewportSize(viewport);
      await page.goto(`/tickets/${ticketId}`);
      await expect(page.locator('.action-bar-title')).toHaveText(title);
      expect(
        await pageOverflow(page),
        `the ${title.length}-character title widened the detail page at ${viewport.width}px`
      ).toBeLessThanOrEqual(1);

      await page.goto(`/tickets?q=${encodeURIComponent(prefix)}`);
      await expect(page.locator('.ticket-queue-main strong', { hasText: prefix })).toBeVisible();
      expect(
        await pageOverflow(page),
        `the ${title.length}-character title widened the queue at ${viewport.width}px`
      ).toBeLessThanOrEqual(1);
    }

    expect(consoleErrors).toEqual([]);
    expect(mysqlValue(`SELECT title FROM tickets WHERE id = ${ticketId}`)).toBe(title);
  } finally {
    await context.close();
  }
});

test('mobile attachment preview contains a long filename and reports the file limit without page overflow', async ({
  browser,
}) => {
  const context = await browser.newContext({
    storageState: requesterState,
    viewport: { width: 375, height: 812 },
  });

  try {
    const page = await context.newPage();
    await page.goto('/tickets/create');

    const longFilename = `${'ชื่อไฟล์ภาษาไทยยาวมาก'.repeat(12)}.txt`;
    await page.locator('input[name="attachments[]"]').setInputFiles({
      name: longFilename,
      mimeType: 'text/plain',
      buffer: Buffer.from('E2E attachment preview only'),
    });

    await expect(page.locator('[data-ticket-attachment-status]')).toContainText('เลือกแล้ว 1 รูป');
    await expect(page.locator('.attachment-preview-meta strong')).toHaveText(longFilename);
    const filenameLayout = await page.locator('.attachment-preview-meta strong').evaluate((element) => ({
      clipped: element.scrollWidth > element.clientWidth,
      cardWidth: element.closest('.attachment-preview-card')?.getBoundingClientRect().width ?? 0,
    }));
    expect(filenameLayout.clipped, 'a long filename should truncate inside its card').toBe(true);
    expect(filenameLayout.cardWidth).toBeGreaterThan(0);
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);

    await page.locator('input[name="attachments[]"]').setInputFiles(
      Array.from({ length: 4 }, (_, index) => ({
        name: `E2E-over-limit-${index}.txt`,
        mimeType: 'text/plain',
        buffer: Buffer.from(String(index)),
      }))
    );
    await expect(page.locator('[data-ticket-attachment-status]')).toContainText('ระบบรับได้สูงสุด 3 รูป');
    await expect(page.locator('[data-ticket-attachment-status]')).toHaveClass(/is-warning/);
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);
  } finally {
    await context.close();
  }
});

test('required-field validation blocks an empty ticket before any request leaves the page', async ({
  browser,
}) => {
  const context = await browser.newContext({
    storageState: requesterState,
    viewport: { width: 375, height: 812 },
  });

  try {
    const page = await context.newPage();
    let createRequests = 0;
    page.on('request', (request) => {
      const requestUrl = new URL(request.url());
      if (request.method() === 'POST' && requestUrl.pathname === '/tickets') {
        createRequests += 1;
      }
    });

    await page.goto('/tickets/create');
    await page.getByRole('button', { name: 'ส่งคำขอแจ้งซ่อม' }).click();

    await expect(page).toHaveURL(/\/tickets\/create$/);
    await expect(page.locator('input[name="title"]')).toBeFocused();
    expect(await page.locator('input[name="title"]').evaluate((input: HTMLInputElement) => input.validationMessage))
      .not.toBe('');
    expect(createRequests, 'native validation should stop the invalid form before POST').toBe(0);
  } finally {
    await context.close();
  }
});

test('a simulated 1,000-row mobile queue stays within the page and the final row remains reachable', async ({
  browser,
}) => {
  const context = await browser.newContext({
    storageState: adminState,
    viewport: { width: 375, height: 812 },
  });

  try {
    const page = await context.newPage();
    await page.goto('/tickets');
    await expect(page.locator('.ticket-queue > li').first()).toBeVisible();

    const rowCount = await page.locator('.ticket-queue').evaluate((list) => {
      const template = list.querySelector(':scope > li');
      if (!template) return 0;
      const fragment = document.createDocumentFragment();
      for (let index = 0; index < 1_000; index += 1) {
        const clone = template.cloneNode(true) as HTMLElement;
        const title = clone.querySelector('.ticket-queue-main strong');
        if (title) title.textContent = `E2E แถวจำลอง ${index + 1}`;
        fragment.appendChild(clone);
      }
      list.replaceChildren(fragment);
      return list.querySelectorAll(':scope > li').length;
    });

    expect(rowCount).toBe(1_000);
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);
    await page.locator('.ticket-queue > li').last().scrollIntoViewIfNeeded();
    await expect(page.locator('.ticket-queue > li').last()).toBeVisible();
  } finally {
    await context.close();
  }
});

test('billion-scale report values stay inside their cards on mobile', async ({ browser }) => {
  const context = await browser.newContext({
    storageState: managerState,
    viewport: { width: 375, height: 812 },
  });

  try {
    const page = await context.newPage();
    await page.goto('/reports/trend');
    await expect(page.locator('.metric-card').first()).toBeVisible();

    const overflowedCards = await page.locator('.metric-card').evaluateAll((cards) => {
      const huge = '9,999,999,999.99%';
      const overflowed: string[] = [];
      cards.forEach((card, index) => {
        const value = card.querySelector<HTMLElement>('.metric-value');
        if (!value) return;
        value.textContent = huge;
        const cardRect = card.getBoundingClientRect();
        const valueRect = value.getBoundingClientRect();
        if (valueRect.left < cardRect.left - 1 || valueRect.right > cardRect.right + 1) {
          overflowed.push(`card ${index + 1}: ${Math.round(valueRect.left)}..${Math.round(valueRect.right)}`);
        }
      });
      return overflowed;
    });

    expect(overflowedCards, 'large report values must not spill outside their metric cards').toEqual([]);
    expect(await pageOverflow(page)).toBeLessThanOrEqual(1);
  } finally {
    await context.close();
  }
});
