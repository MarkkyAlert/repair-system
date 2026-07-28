import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test';
import path from 'node:path';
import { mysqlValue } from '../helpers/db';

type Role = 'public' | 'requester' | 'technician' | 'manager' | 'admin';
type Screen = {
  id: string;
  role: Role;
  path: string;
  status?: number;
};

type UiIssue = {
  screen: string;
  role: Role;
  path: string;
  kind: string;
  detail: string;
};

const authDir = path.join(__dirname, '..', '.auth');
const stateFor = (role: Exclude<Role, 'public'>) => path.join(authDir, `${role}.json`);

function matrixScreens(): Screen[] {
  const anyTicketId = mysqlValue('SELECT id FROM tickets ORDER BY id LIMIT 1');
  const requesterTicketId = mysqlValue('SELECT id FROM tickets WHERE requester_id = 1 ORDER BY id LIMIT 1');
  const technicianTicketId = mysqlValue(
    'SELECT id FROM tickets WHERE assigned_technician_id = 3 ORDER BY id LIMIT 1'
  );
  const assetId = mysqlValue("SELECT id FROM assets WHERE status <> 'disposed' ORDER BY id LIMIT 1");
  const scanToken = mysqlValue(
    'SELECT token FROM asset_qr_tokens WHERE is_active = 1 ORDER BY id LIMIT 1'
  );

  expect(anyTicketId, 'test DB needs at least one ticket for the UI route matrix').not.toBe('');
  expect(requesterTicketId, 'test DB needs a ticket owned by the seeded requester').not.toBe('');
  expect(technicianTicketId, 'test DB needs a ticket assigned to the seeded technician').not.toBe('');
  expect(assetId, 'test DB needs an active asset for detail/edit/scan routes').not.toBe('');
  expect(scanToken, 'test DB needs an active QR token for the public scan routes').not.toBe('');

  const reports = [
    ['reports', '/reports'],
    ['reports-guide', '/reports/guide'],
    ['report-asset-reliability', '/reports/asset-reliability'],
    ['report-sla-breach', '/reports/sla-breach'],
    ['report-technician', '/reports/technician-performance'],
    ['report-hotspot', '/reports/problem-hotspot'],
    ['report-trend', '/reports/trend'],
    ['report-executive', '/reports/executive'],
    ['report-backlog', '/reports/backlog-aging'],
    ['report-reopen', '/reports/reopen-rate'],
    ['report-csat', '/reports/csat'],
  ] as const;

  const assetScreens = [
    ['assets', '/asset-registry'],
    ['assets-empty', '/asset-registry?q=__E2E_UI_NO_MATCH__'],
    ['asset-create', '/asset-registry/create'],
    ['asset-import', '/asset-registry/import'],
    ['asset-print', '/asset-registry/print'],
    ['asset-detail', `/asset-registry/${assetId}`],
    ['asset-edit', `/asset-registry/${assetId}/edit`],
  ] as const;

  const commonScreens = (role: Exclude<Role, 'public'>, ticketId: string): Screen[] => [
    { id: `${role}-dashboard`, role, path: '/dashboard' },
    { id: `${role}-tickets`, role, path: '/tickets' },
    { id: `${role}-tickets-empty`, role, path: '/tickets?q=__E2E_UI_NO_MATCH__' },
    { id: `${role}-ticket-detail`, role, path: `/tickets/${ticketId}` },
    { id: `${role}-profile`, role, path: '/profile' },
    { id: `${role}-notifications`, role, path: '/notifications' },
  ];

  const adminTabs = [
    'users',
    'departments',
    'categories',
    'asset-categories',
    'locations',
    'priorities',
    'roles',
    'settings',
    'security',
    'email',
    'backup',
    'audit',
  ];

  return [
    { id: 'login', role: 'public', path: '/login' },
    { id: 'forgot-password', role: 'public', path: '/forgot-password' },
    { id: 'track', role: 'public', path: '/track' },
    { id: 'scan-asset', role: 'public', path: `/scan/${scanToken}` },
    { id: 'scan-report', role: 'public', path: `/scan/${scanToken}/report` },
    { id: 'error-404', role: 'public', path: '/e2e-ui-not-found', status: 404 },

    ...commonScreens('requester', requesterTicketId),
    { id: 'requester-ticket-create', role: 'requester', path: '/tickets/create' },
    { id: 'requester-change-password', role: 'requester', path: '/change-password' },
    { id: 'requester-notification-preferences', role: 'requester', path: '/profile/notifications' },

    ...commonScreens('technician', technicianTicketId),
    ...commonScreens('manager', anyTicketId),
    ...assetScreens.map(([id, route]) => ({ id: `manager-${id}`, role: 'manager' as const, path: route })),
    ...reports.map(([id, route]) => ({ id: `manager-${id}`, role: 'manager' as const, path: route })),

    ...commonScreens('admin', anyTicketId),
    ...assetScreens.map(([id, route]) => ({ id: `admin-${id}`, role: 'admin' as const, path: route })),
    ...reports.map(([id, route]) => ({ id: `admin-${id}`, role: 'admin' as const, path: route })),
    ...adminTabs.map((tab) => ({ id: `admin-${tab}`, role: 'admin' as const, path: `/admin#tab-${tab}` })),
    { id: 'admin-broadcast', role: 'admin', path: '/admin/broadcast' },
    { id: 'admin-email-queue', role: 'admin', path: '/admin/email-queue' },
    { id: 'admin-email-templates', role: 'admin', path: '/admin/email-templates' },
    { id: 'admin-email-template-edit', role: 'admin', path: '/admin/email-templates/ticket_created' },
    { id: 'admin-guest-requests', role: 'admin', path: '/admin/guest-requests' },
    { id: 'admin-user-import', role: 'admin', path: '/admin/users/import' },
  ];
}

async function inspectRenderedPage(page: Page, viewportWidth: number): Promise<Array<[string, string]>> {
  return page.evaluate((width) => {
    const findings: Array<[string, string]> = [];
    const root = document.documentElement;
    const horizontalOverflow = Math.max(0, root.scrollWidth - root.clientWidth);
    if (horizontalOverflow > 1) {
      findings.push(['page-overflow', `document is ${horizontalOverflow}px wider than the viewport`]);
    }

    const missingIcons = document.querySelectorAll('[data-missing-icon]').length;
    if (missingIcons > 0) {
      findings.push(['missing-icon', `${missingIcons} placeholder(s) rendered`]);
    }

    const brokenImages = [...document.querySelectorAll<HTMLImageElement>('img[src]')]
      .filter((img) => img.complete && img.naturalWidth === 0)
      .map((img) => img.currentSrc || img.src);
    if (brokenImages.length > 0) {
      findings.push(['broken-image', brokenImages.join(', ')]);
    }

    const visible = (element: HTMLElement): boolean => {
      const rect = element.getBoundingClientRect();
      if (typeof element.checkVisibility === 'function'
        && !element.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })) {
        return false;
      }

      if (element.closest('[hidden], [inert], [aria-hidden="true"], dialog:not([open])')) {
        return false;
      }

      const closedDetails = element.closest('details:not([open])');
      if (closedDetails && element !== closedDetails.querySelector(':scope > summary')) {
        return false;
      }

      let ancestor: HTMLElement | null = element;
      while (ancestor) {
        const style = getComputedStyle(ancestor);
        if (style.display === 'none'
          || style.visibility === 'hidden'
          || style.pointerEvents === 'none'
          || Number(style.opacity) === 0) {
          return false;
        }
        ancestor = ancestor.parentElement;
      }

      return rect.right > 0
        && rect.left < width
        && rect.bottom > 0
        && rect.top < window.innerHeight
        && rect.width > 0
        && rect.height > 0;
    };

    const actions = document.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled]), summary, input[type="submit"]:not([disabled]), [role="button"]'
    );
    let actionIndex = 0;
    for (const action of actions) {
      action.dataset.uiAuditAction = String(actionIndex++);
      if (!visible(action)) continue;
      const rect = action.getClientRects()[0] ?? action.getBoundingClientRect();
      const name = (action.getAttribute('aria-label') || action.textContent || action.tagName)
        .trim()
        .replace(/\s+/g, ' ')
        .slice(0, 80);

      let visibleLeft = Math.max(0, rect.left);
      let visibleRight = Math.min(width, rect.right);
      let visibleTop = Math.max(0, rect.top);
      let visibleBottom = Math.min(window.innerHeight, rect.bottom);
      let parent = action.parentElement;
      while (parent && parent !== document.body) {
        const style = getComputedStyle(parent);
        const parentRect = parent.getBoundingClientRect();
        if (['auto', 'scroll', 'hidden', 'clip'].includes(style.overflowX)) {
          visibleLeft = Math.max(visibleLeft, parentRect.left);
          visibleRight = Math.min(visibleRight, parentRect.right);
        }
        if (['auto', 'scroll', 'hidden', 'clip'].includes(style.overflowY)) {
          visibleTop = Math.max(visibleTop, parentRect.top);
          visibleBottom = Math.min(visibleBottom, parentRect.bottom);
        }
        parent = parent.parentElement;
      }
      if (visibleRight - visibleLeft <= 1 || visibleBottom - visibleTop <= 1) continue;

      const centerX = (visibleLeft + visibleRight) / 2;
      const centerY = (visibleTop + visibleBottom) / 2;
      const hit = document.elementFromPoint(centerX, centerY);
      if (hit && hit !== action && !action.contains(hit)) {
        findings.push([
          'covered-action-candidate',
          JSON.stringify({
            id: action.dataset.uiAuditAction,
            name: name || action.tagName,
            hit: `${hit.tagName.toLowerCase()}.${hit.className}`,
          }),
        ]);
      }
    }

    return findings;
  }, viewportWidth);
}

async function contextForRole(
  browser: Browser,
  role: Role,
  viewport: { width: number; height: number }
): Promise<BrowserContext> {
  if (role === 'public') return browser.newContext({ viewport });
  return browser.newContext({ storageState: stateFor(role), viewport });
}

for (const viewport of [
  { name: 'mobile', width: 375, height: 812 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 1000 },
]) {
  test(`UI route matrix: console/resources/overflow/actions at ${viewport.name} ${viewport.width}px`, async ({
    browser,
  }) => {
    test.setTimeout(10 * 60_000);
    const issues: UiIssue[] = [];
    const contexts = new Map<Role, BrowserContext>();

    try {
      for (const screen of matrixScreens()) {
        let context = contexts.get(screen.role);
        if (!context) {
          context = await contextForRole(browser, screen.role, { width: viewport.width, height: viewport.height });
          contexts.set(screen.role, context);
        }

        const page = await context.newPage();
        const consoleErrors: string[] = [];
        const pageErrors: string[] = [];
        const responseErrors: string[] = [];
        const requestFailures: string[] = [];

        page.on('console', (message) => {
          if (message.type() === 'error') consoleErrors.push(message.text());
        });
        page.on('pageerror', (error) => pageErrors.push(error.message));
        page.on('response', (response) => {
          if (response.status() >= 400 && !(screen.status === response.status() && response.request().isNavigationRequest())) {
            responseErrors.push(`${response.status()} ${response.url()}`);
          }
        });
        page.on('requestfailed', (request) => {
          const reason = request.failure()?.errorText || 'request failed';
          if (reason !== 'net::ERR_ABORTED') requestFailures.push(`${reason} ${request.url()}`);
        });

        try {
          const response = await page.goto(screen.path, { waitUntil: 'domcontentloaded' });
          await page.waitForTimeout(250);
          const expectedStatus = screen.status ?? 200;
          const actualStatus = response?.status() ?? 0;
          if (actualStatus !== expectedStatus) {
            issues.push({
              screen: screen.id,
              role: screen.role,
              path: screen.path,
              kind: 'document-status',
              detail: `expected ${expectedStatus}, got ${actualStatus} at ${page.url()}`,
            });
          }

          const expectedNavigationError = screen.status !== undefined && screen.status >= 400;
          for (const detail of consoleErrors) {
            if (expectedNavigationError && detail.startsWith('Failed to load resource:')) continue;
            issues.push({ screen: screen.id, role: screen.role, path: screen.path, kind: 'console-error', detail });
          }
          for (const detail of pageErrors) {
            issues.push({ screen: screen.id, role: screen.role, path: screen.path, kind: 'page-error', detail });
          }
          for (const detail of responseErrors) {
            issues.push({ screen: screen.id, role: screen.role, path: screen.path, kind: 'http-error', detail });
          }
          for (const detail of requestFailures) {
            issues.push({ screen: screen.id, role: screen.role, path: screen.path, kind: 'request-failed', detail });
          }
          for (const [kind, detail] of await inspectRenderedPage(page, viewport.width)) {
            if (kind !== 'covered-action-candidate') {
              issues.push({ screen: screen.id, role: screen.role, path: screen.path, kind, detail });
              continue;
            }

            const candidate = JSON.parse(detail) as { id: string; name: string; hit: string };
            try {
              await page.locator(`[data-ui-audit-action="${candidate.id}"]`).click({
                trial: true,
                timeout: 1_000,
              });
            } catch {
              issues.push({
                screen: screen.id,
                role: screen.role,
                path: screen.path,
                kind: 'covered-action',
                detail: `${candidate.name} cannot be clicked; centre hit ${candidate.hit}`,
              });
            }
          }
        } catch (error) {
          issues.push({
            screen: screen.id,
            role: screen.role,
            path: screen.path,
            kind: 'capture-error',
            detail: error instanceof Error ? error.message : String(error),
          });
        } finally {
          await page.close();
        }
      }
    } finally {
      for (const context of contexts.values()) {
        await context.close();
      }
    }

    expect(issues, JSON.stringify(issues, null, 2)).toEqual([]);
  });
}
