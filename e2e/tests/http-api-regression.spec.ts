import { APIRequestContext, APIResponse, expect, request, test } from '@playwright/test';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { mysqlExec, mysqlInt, mysqlRows, mysqlValue, sqlString } from '../helpers/db';

const ROOT = path.resolve(__dirname, '..', '..');
const BASE_URL = `http://127.0.0.1:${Number(process.env.E2E_PORT || 8123)}`;
const RATE_FILE = path.resolve(ROOT, 'storage/logs/login_rate_limits.json');
const LOG_FILE = process.env.QA_API_RUN_LOG
  ? path.resolve(process.env.QA_API_RUN_LOG)
  : path.resolve(__dirname, '..', 'test-results', 'api-run.jsonl');

const accounts = {
  requester: { login: 'requester', password: 'requester123' },
  manager: { login: 'manager', password: 'manager123' },
  technician: { login: 'technician', password: 'tech12345' },
  admin: { login: 'admin', password: 'admin12345' },
} as const;

type Category =
  | 'happy_path'
  | 'authentication'
  | 'validation'
  | 'edge'
  | 'security'
  | 'role_permission'
  | 'csrf_method'
  | 'read_only'
  | 'cleanup';

type CaseMeta = {
  id: string;
  priority: 'P0' | 'P1' | 'P2';
  category: Category;
  title: string;
};

type RequestOptions = {
  headers?: Record<string, string>;
  form?: Record<string, string | number | boolean>;
  data?: string | Buffer;
  multipart?: FormData | Record<string, string | number | boolean | {
    name: string;
    mimeType: string;
    buffer: Buffer;
  }>;
  logBody?: unknown;
};

type Captured = {
  response: APIResponse;
  status: number;
  headers: Record<string, string>;
  body: string;
};

type HttpStep = {
  request: {
    method: string;
    url: string;
    headers: Record<string, unknown>;
    body: unknown;
  };
  response: {
    status: number;
    headers: Record<string, string>;
    body: string;
  };
  duration_ms: number;
};

function secretKey(key: string): boolean {
  return /password|cookie|authorization|csrf|token|secret|api[_-]?key/i.test(key);
}

function mask(value: unknown, key = ''): unknown {
  if (secretKey(key)) return '[MASKED]';
  if (Array.isArray(value)) return value.map((entry) => mask(entry));
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([childKey, child]) => [childKey, mask(child, childKey)]));
  }
  return value;
}

function sanitizeBody(body: string): string {
  return body
    .replace(
      /(<input\b[^>]*\bname=["'](?:_csrf|submission_token|token)["'][^>]*\bvalue=["'])[^"']*/gi,
      '$1[MASKED]'
    )
    .replace(/(\/reset-password\/)[a-z0-9_-]+/gi, '$1[MASKED]')
    .replace(/([?&](?:token|api_key|key)=)[^&"'<\s]+/gi, '$1[MASKED]')
    .replace(/\b[a-f0-9]{64}\b/gi, '[MASKED]');
}

function excerpt(body: string): string {
  const safe = sanitizeBody(body);
  return safe.length <= 1600 ? safe : `${safe.slice(0, 1600)}…[truncated]`;
}

function extractInput(html: string, name: string): string {
  const tags = html.match(/<input\b[^>]*>/gi) ?? [];
  for (const tag of tags) {
    const nameMatch = tag.match(/\bname=["']([^"']+)["']/i);
    if (nameMatch?.[1] !== name) continue;
    return tag.match(/\bvalue=["']([^"']*)["']/i)?.[1] ?? '';
  }
  throw new Error(`input[name="${name}"] was not found`);
}

function locationOf(captured: Captured): string {
  return captured.headers.location ?? '';
}

function assertJsonObject(body: string): Record<string, unknown> {
  const parsed: unknown = JSON.parse(body);
  expect(parsed).not.toBeNull();
  expect(Array.isArray(parsed)).toBe(false);
  expect(typeof parsed).toBe('object');
  return parsed as Record<string, unknown>;
}

function uniqueHex(): string {
  return crypto.randomBytes(32).toString('hex');
}

function ticketForm(stamp: string, overrides: Record<string, string> = {}): Record<string, string> {
  return {
    title: `E2E-API-${stamp} ticket`,
    description: 'HTTP API regression evidence; safe to delete.',
    priority_id: '1',
    ticket_category_id: '1',
    location_id: '1',
    asset_id: '',
    impact_level: 'medium',
    urgency_level: 'medium',
    submission_token: uniqueHex(),
    ...overrides,
  };
}

function assetForm(stamp: string, overrides: Record<string, string> = {}): Record<string, string> {
  return {
    asset_code: `E2E-API-${stamp}`.slice(0, 60),
    name: `E2E API Asset ${stamp}`,
    serial_number: '',
    asset_category_id: '1',
    department_id: '1',
    location_id: '1',
    custodian_user_id: '',
    brand: 'QA',
    model: 'HTTP',
    vendor: '',
    purchase_date: '',
    warranty_expires_at: '',
    status: 'active',
    notes: 'Safe API regression asset.',
    ...overrides,
  };
}

class MatrixRunner {
  failures: string[] = [];
  private activeSteps: HttpStep[] = [];

  constructor() {
    fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
    fs.writeFileSync(LOG_FILE, '');
  }

  async send(
    context: APIRequestContext,
    method: string,
    url: string,
    options: RequestOptions = {}
  ): Promise<Captured> {
    const started = Date.now();
    const response = await context.fetch(url, {
      method,
      headers: options.headers,
      form: options.form,
      data: options.data,
      multipart: options.multipart,
      maxRedirects: 0,
    });
    const body = await response.text();
    const captured: Captured = {
      response,
      status: response.status(),
      headers: response.headers(),
      body,
    };
    this.activeSteps.push({
      request: {
        method,
        url,
        headers: mask(options.headers ?? {}) as Record<string, unknown>,
        body: mask(options.logBody ?? options.form ?? (options.data ? '[raw body]' : null)),
      },
      response: {
        status: captured.status,
        headers: Object.fromEntries(
          Object.entries(captured.headers).map(([key, value]) => [key, secretKey(key) ? '[MASKED]' : value])
        ),
        body: excerpt(body),
      },
      duration_ms: Date.now() - started,
    });
    return captured;
  }

  async run(meta: CaseMeta, callback: () => Promise<void>): Promise<void> {
    await test.step(`${meta.id} ${meta.title}`, async () => {
      const startedAt = new Date();
      const started = Date.now();
      this.activeSteps = [];
      let result: 'passed' | 'failed' = 'passed';
      let error = '';

      try {
        await callback();
      } catch (caught) {
        result = 'failed';
        error = caught instanceof Error ? caught.message : String(caught);
        this.failures.push(`${meta.id}: ${error}`);
      }

      const first = this.activeSteps[0] ?? null;
      const last = this.activeSteps.at(-1) ?? null;
      fs.appendFileSync(
        LOG_FILE,
        JSON.stringify({
          test_id: meta.id,
          priority: meta.priority,
          category: meta.category,
          title: meta.title,
          request: first?.request ?? null,
          response: last?.response ?? null,
          result,
          duration_ms: Date.now() - started,
          timestamp: startedAt.toISOString(),
          error,
          steps: this.activeSteps,
        }) + '\n'
      );
    });
  }
}

async function csrfFrom(runner: MatrixRunner, context: APIRequestContext, pathName: string): Promise<string> {
  const page = await runner.send(context, 'GET', pathName);
  expect(page.status).toBe(200);
  return extractInput(page.body, '_csrf');
}

async function loginContext(
  runner: MatrixRunner,
  login: string,
  password: string
): Promise<{ context: APIRequestContext; csrf: string }> {
  const context = await request.newContext({ baseURL: BASE_URL });
  const csrf = await csrfFrom(runner, context, '/login');
  const response = await runner.send(context, 'POST', '/login', {
    form: { _csrf: csrf, login, password, return_to: '/dashboard' },
  });
  expect(response.status).toBe(302);
  expect(locationOf(response)).toBe('/dashboard');
  // A successful login deliberately discards the token minted before authentication (AuthService::attemptLogin,
  // mirroring logout), so the pre-auth value cannot guard authenticated writes. Read the session's new token
  // from an authenticated page — reusing the pre-login one would now be silently rejected on every POST.
  const authenticatedCsrf = await csrfFrom(runner, context, '/dashboard');
  expect(authenticatedCsrf).not.toBe(csrf);
  return { context, csrf: authenticatedCsrf };
}

test('HTTP API regression matrix: routing, auth, CSRF, validation, permission, IDOR, upload and cleanup', async () => {
  test.setTimeout(240_000);

  const runner = new MatrixRunner();
  const stamp = `${Date.now()}`;
  const ownerUsername = `e2eapi${stamp}`.slice(0, 40);
  const ownerEmail = `${ownerUsername}@e2e.test`;
  const ownerPassword = 'E2eApiPass123!';
  const trackedTicketIds: number[] = [];
  const trackedAssetIds: number[] = [];
  const contexts: APIRequestContext[] = [];
  const rateFileExisted = fs.existsSync(RATE_FILE);
  const rateFileBaseline = rateFileExisted ? fs.readFileSync(RATE_FILE, 'utf8') : '';
  const baselines = {
    audit: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM audit_logs'),
    email: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM email_queue'),
    export: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM export_jobs'),
    login: mysqlInt('SELECT COALESCE(MAX(id), 0) FROM login_attempts'),
  };

  let admin!: { context: APIRequestContext; csrf: string };
  let manager!: { context: APIRequestContext; csrf: string };
  let requesterUser!: { context: APIRequestContext; csrf: string };
  let technician!: { context: APIRequestContext; csrf: string };
  let owner!: { context: APIRequestContext; csrf: string };
  let ownerUserId = 0;
  let primaryTicketId = 0;
  let primaryTicketToken = '';
  let commentId = 0;
  let assetId = 0;
  let validAttachmentId = 0;
  let validAttachmentContent = '';

  const restoreRateFile = (): void => {
    if (rateFileExisted) {
      fs.writeFileSync(RATE_FILE, rateFileBaseline);
    } else {
      fs.rmSync(RATE_FILE, { force: true });
    }
  };

  const cleanup = (): void => {
    const paths = mysqlRows(
      "SELECT DISTINCT ta.disk_path FROM ticket_attachments ta INNER JOIN tickets t ON t.id = ta.ticket_id " +
        "LEFT JOIN users u ON u.id = t.requester_id " +
        "WHERE t.title LIKE '%E2E-API-%' OR u.username LIKE 'e2eapi%'"
    );
    for (const [relative] of paths) {
      const absolute = path.resolve(ROOT, relative.replace(/^\/+/, ''));
      const uploadRoot = path.resolve(ROOT, 'storage/uploads/tickets') + path.sep;
      if (absolute.startsWith(uploadRoot)) fs.rmSync(absolute, { force: true });
    }

    mysqlExec(
      "DELETE nr FROM notification_recipients nr INNER JOIN notifications n ON n.id = nr.notification_id " +
        "INNER JOIN tickets t ON n.related_type = 'ticket' AND n.related_id = t.id " +
        "LEFT JOIN users u ON u.id = t.requester_id " +
        "WHERE t.title LIKE '%E2E-API-%' OR u.username LIKE 'e2eapi%'"
    );
    mysqlExec(
      "DELETE n FROM notifications n INNER JOIN tickets t ON n.related_type = 'ticket' AND n.related_id = t.id " +
        "LEFT JOIN users u ON u.id = t.requester_id " +
        "WHERE t.title LIKE '%E2E-API-%' OR u.username LIKE 'e2eapi%'"
    );
    mysqlExec(
      "DELETE t FROM tickets t LEFT JOIN users u ON u.id = t.requester_id " +
        "WHERE t.title LIKE '%E2E-API-%' OR u.username LIKE 'e2eapi%'"
    );
    mysqlExec("DELETE FROM assets WHERE asset_code LIKE 'E2E-API-%'");
    mysqlExec(`DELETE FROM audit_logs WHERE id > ${baselines.audit}`);
    mysqlExec(`DELETE FROM email_queue WHERE id > ${baselines.email}`);
    mysqlExec(`DELETE FROM export_jobs WHERE id > ${baselines.export}`);
    mysqlExec(`DELETE FROM login_attempts WHERE id > ${baselines.login}`);
    mysqlExec("DELETE FROM password_resets WHERE email LIKE '%@e2e.test'");
    mysqlExec("DELETE FROM users WHERE username LIKE 'e2eapi%' OR email LIKE '%@e2e.test'");
    restoreRateFile();
  };

  try {
    fs.mkdirSync(path.dirname(RATE_FILE), { recursive: true });
    fs.writeFileSync(RATE_FILE, '{}');

    admin = await loginContext(runner, accounts.admin.login, accounts.admin.password);
    manager = await loginContext(runner, accounts.manager.login, accounts.manager.password);
    requesterUser = await loginContext(runner, accounts.requester.login, accounts.requester.password);
    technician = await loginContext(runner, accounts.technician.login, accounts.technician.password);
    contexts.push(admin.context, manager.context, requesterUser.context, technician.context);

    await runner.run(
      { id: 'HTTP-001', priority: 'P0', category: 'security', title: 'HTML response carries baseline security and request-id headers' },
      async () => {
        const anonymous = await request.newContext({ baseURL: BASE_URL });
        contexts.push(anonymous);
        const response = await runner.send(anonymous, 'GET', '/login');
        expect(response.status).toBe(200);
        expect(response.headers['x-content-type-options']).toBe('nosniff');
        expect(response.headers['x-frame-options']).toBeTruthy();
        expect(response.headers['referrer-policy']).toBeTruthy();
        expect(response.headers['content-security-policy']).toContain("default-src 'self'");
        expect(response.headers['x-request-id']).toMatch(/^[a-f0-9]{8}$/);
      }
    );

    await runner.run(
      { id: 'AUTH-001', priority: 'P0', category: 'authentication', title: 'valid login rotates the session and reaches dashboard' },
      async () => {
        const context = await request.newContext({ baseURL: BASE_URL });
        contexts.push(context);
        const csrf = await csrfFrom(runner, context, '/login');
        const before = (await context.storageState()).cookies.find(
          (cookie) => cookie.name === 'repair_system_session'
        )?.value;
        const login = await runner.send(context, 'POST', '/login', {
          form: {
            _csrf: csrf,
            login: accounts.requester.login,
            password: accounts.requester.password,
            return_to: '/dashboard',
          },
        });
        const after = (await context.storageState()).cookies.find(
          (cookie) => cookie.name === 'repair_system_session'
        )?.value;
        expect(login.status).toBe(302);
        expect(locationOf(login)).toBe('/dashboard');
        expect(before).toBeTruthy();
        expect(after).toBeTruthy();
        expect(after).not.toBe(before);
        expect((await runner.send(context, 'GET', '/dashboard')).status).toBe(200);
      }
    );

    await runner.run(
      { id: 'AUTH-002', priority: 'P0', category: 'authentication', title: 'unauthenticated HTML request redirects to login' },
      async () => {
        const anonymous = await request.newContext({ baseURL: BASE_URL });
        contexts.push(anonymous);
        const response = await runner.send(anonymous, 'GET', '/dashboard');
        expect(response.status).toBe(302);
        expect(locationOf(response)).toContain('/login?return=');
      }
    );

    await runner.run(
      { id: 'AUTH-003', priority: 'P0', category: 'authentication', title: 'unauthenticated JSON request receives 401 JSON envelope' },
      async () => {
        const anonymous = await request.newContext({ baseURL: BASE_URL });
        contexts.push(anonymous);
        const response = await runner.send(anonymous, 'GET', '/notifications/feed', {
          headers: { Accept: 'application/json' },
        });
        expect(response.status).toBe(401);
        expect(response.headers['content-type']).toContain('application/json');
        const json = assertJsonObject(response.body);
        expect(json.success).toBe(false);
        expect(typeof json.message).toBe('string');
        expect(json.reference).toMatch(/^[a-f0-9]{8}$/);
      }
    );

    for (const [id, form] of [
      ['AUTH-004', { login: 'requester', password: 'requester123', return_to: '/dashboard' }],
      ['AUTH-005', { _csrf: 'invalid', login: 'requester', password: 'requester123', return_to: '/dashboard' }],
      ['AUTH-006', { '_csrf[]': 'crafted-array', login: 'requester', password: 'requester123', return_to: '/dashboard' }],
    ] as const) {
      await runner.run(
        {
          id,
          priority: 'P0',
          category: 'csrf_method',
          title: `${id === 'AUTH-004' ? 'missing' : id === 'AUTH-005' ? 'invalid scalar' : 'array'} CSRF cannot authenticate`,
        },
        async () => {
          const context = await request.newContext({ baseURL: BASE_URL });
          contexts.push(context);
          const response = await runner.send(context, 'POST', '/login', { form });
          expect(response.status).toBe(302);
          expect(locationOf(response)).toContain('/login');
          const dashboard = await runner.send(context, 'GET', '/dashboard');
          expect(dashboard.status).toBe(302);
        }
      );
    }

    await runner.run(
      { id: 'AUTH-007', priority: 'P0', category: 'security', title: 'SQL injection payload cannot bypass login' },
      async () => {
        const context = await request.newContext({ baseURL: BASE_URL });
        contexts.push(context);
        const csrf = await csrfFrom(runner, context, '/login');
        const response = await runner.send(context, 'POST', '/login', {
          form: { _csrf: csrf, login: "' OR 1=1 --", password: 'irrelevant', return_to: '/dashboard' },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toContain('/login');
        expect((await runner.send(context, 'GET', '/dashboard')).status).toBe(302);
      }
    );

    await runner.run(
      { id: 'AUTH-008', priority: 'P0', category: 'security', title: 'external return_to cannot create an open redirect' },
      async () => {
        const context = await request.newContext({ baseURL: BASE_URL });
        contexts.push(context);
        const csrf = await csrfFrom(runner, context, '/login');
        const response = await runner.send(context, 'POST', '/login', {
          form: {
            _csrf: csrf,
            login: accounts.requester.login,
            password: accounts.requester.password,
            return_to: 'https://example.invalid/steal',
          },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/dashboard');
      }
    );

    await runner.run(
      { id: 'ROLE-001', priority: 'P0', category: 'role_permission', title: 'requester cannot open admin surface' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', '/admin');
        expect(response.status).toBe(403);
        expect(response.body).not.toContain('สร้างบัญชีผู้ใช้งาน');
      }
    );

    await runner.run(
      { id: 'ROLE-002', priority: 'P0', category: 'role_permission', title: 'requester cannot create an admin-managed user' },
      async () => {
        const username = `e2eapirole${stamp}`.slice(0, 45);
        const response = await runner.send(requesterUser.context, 'POST', '/admin/users', {
          form: {
            _csrf: requesterUser.csrf,
            username,
            full_name: 'Role Bypass',
            email: `${username}@e2e.test`,
            role: 'admin',
            password: ownerPassword,
            password_confirmation: ownerPassword,
            is_active: '1',
          },
        });
        expect(response.status).toBe(403);
        expect(mysqlInt(`SELECT COUNT(*) FROM users WHERE username = ${sqlString(username)}`)).toBe(0);
      }
    );

    await runner.run(
      { id: 'ROLE-003', priority: 'P1', category: 'role_permission', title: 'requester is denied report access' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', '/reports');
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/dashboard');
      }
    );

    await runner.run(
      { id: 'ROLE-004', priority: 'P1', category: 'role_permission', title: 'manager can open reports' },
      async () => {
        const response = await runner.send(manager.context, 'GET', '/reports');
        expect(response.status).toBe(200);
        expect(response.body).toContain('รายงาน');
      }
    );

    for (const [id, mode] of [
      ['CSRF-001', 'missing'],
      ['CSRF-002', 'invalid'],
      ['CSRF-003', 'text'],
    ] as const) {
      await runner.run(
        {
          id,
          priority: 'P0',
          category: 'csrf_method',
          title: `${id === 'CSRF-001' ? 'missing' : id === 'CSRF-002' ? 'invalid' : 'text/plain'} CSRF cannot mutate admin users`,
        },
        async () => {
          const username = `e2eapicsrf${id.slice(-1)}${stamp}`.slice(0, 45);
          const validPayload = {
            username,
            full_name: 'CSRF Guard Evidence',
            email: `${username}@e2e.test`,
            role: 'requester',
            department_id: '1',
            password: ownerPassword,
            password_confirmation: ownerPassword,
            is_active: '1',
          };
          const requestOptions: RequestOptions =
            mode === 'missing'
              ? { form: validPayload }
              : mode === 'invalid'
                ? { form: { _csrf: 'invalid', ...validPayload } }
                : {
                    headers: { 'Content-Type': 'text/plain' },
                    data: new URLSearchParams({ _csrf: admin.csrf, ...validPayload }).toString(),
                    logBody: { _csrf: admin.csrf, ...validPayload },
                  };
          const before = mysqlInt(`SELECT COUNT(*) FROM users WHERE username = ${sqlString(username)}`);
          const response = await runner.send(admin.context, 'POST', '/admin/users', requestOptions);
          expect(response.status).toBe(302);
          expect(locationOf(response)).toBe('/admin');
          expect(mysqlInt(`SELECT COUNT(*) FROM users WHERE username = ${sqlString(username)}`)).toBe(before);
        }
      );
    }

    await runner.run(
      { id: 'METHOD-001', priority: 'P1', category: 'csrf_method', title: 'GET cannot invoke POST-only logout' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', '/logout');
        expect(response.status).toBe(404);
        expect((await runner.send(requesterUser.context, 'GET', '/dashboard')).status).toBe(200);
      }
    );

    await runner.run(
      { id: 'METHOD-002', priority: 'P1', category: 'csrf_method', title: 'PATCH cannot invoke POST-only user update' },
      async () => {
        const before = mysqlValue("SELECT full_name FROM users WHERE username = 'requester'");
        const response = await runner.send(admin.context, 'PATCH', '/admin/users/1', {
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          data: `_csrf=${encodeURIComponent(admin.csrf)}&full_name=METHOD_BYPASS`,
          logBody: { _csrf: admin.csrf, full_name: 'METHOD_BYPASS' },
        });
        expect(response.status).toBe(404);
        expect(mysqlValue("SELECT full_name FROM users WHERE username = 'requester'")).toBe(before);
      }
    );

    await runner.run(
      { id: 'ROUTE-001', priority: 'P0', category: 'security', title: 'malformed numeric id cannot coerce to a real resource' },
      async () => {
        const response = await runner.send(admin.context, 'GET', '/tickets/1junk');
        expect(response.status).toBe(404);
      }
    );

    await runner.run(
      { id: 'ROUTE-002', priority: 'P1', category: 'security', title: 'path traversal-shaped attachment id is rejected by routing' },
      async () => {
        const response = await runner.send(admin.context, 'GET', '/attachments/..%2F..%2Fetc%2Fpasswd');
        expect(response.status).toBe(404);
        expect(response.body).not.toContain('root:x:');
      }
    );

    await runner.run(
      { id: 'ADMIN-001', priority: 'P0', category: 'happy_path', title: 'admin creates a requester through the HTTP form stack' },
      async () => {
        const response = await runner.send(admin.context, 'POST', '/admin/users', {
          form: {
            _csrf: admin.csrf,
            username: ownerUsername,
            full_name: `E2E API Owner ${stamp}`,
            email: ownerEmail,
            phone: '0812345678',
            role: 'requester',
            department_id: '1',
            password: ownerPassword,
            password_confirmation: ownerPassword,
            is_active: '1',
          },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/admin');
        ownerUserId = Number(mysqlValue(`SELECT id FROM users WHERE username = ${sqlString(ownerUsername)}`));
        expect(ownerUserId).toBeGreaterThan(0);
        expect(mysqlRows(`SELECT role,is_active,version FROM users WHERE id = ${ownerUserId}`)).toEqual([
          ['requester', '1', '1'],
        ]);
      }
    );

    const invalidUsers: Array<[string, string, Record<string, string>, string]> = [
      ['VAL-001', 'missing required fields', { username: `e2eapimissing${stamp}` }, 'e2eapimissing'],
      [
        'VAL-002',
        'invalid email',
        {
          username: `e2eapibadmail${stamp}`,
          full_name: 'Bad Mail',
          email: 'not-an-email',
          role: 'requester',
          password: ownerPassword,
          password_confirmation: ownerPassword,
          is_active: '1',
        },
        'e2eapibadmail',
      ],
      [
        'VAL-003',
        'invalid role',
        {
          username: `e2eapibadrole${stamp}`,
          full_name: 'Bad Role',
          email: `e2eapibadrole${stamp}@e2e.test`,
          role: 'superadmin',
          password: ownerPassword,
          password_confirmation: ownerPassword,
          is_active: '1',
        },
        'e2eapibadrole',
      ],
      [
        'VAL-004',
        'malformed foreign key',
        {
          username: `e2eapibadfk${stamp}`,
          full_name: 'Bad FK',
          email: `e2eapibadfk${stamp}@e2e.test`,
          role: 'requester',
          department_id: '1junk',
          password: ownerPassword,
          password_confirmation: ownerPassword,
          is_active: '1',
        },
        'e2eapibadfk',
      ],
      [
        'VAL-005',
        'overlong full name',
        {
          username: `e2eapilong${stamp}`,
          full_name: 'A'.repeat(151),
          email: `e2eapilong${stamp}@e2e.test`,
          role: 'requester',
          password: ownerPassword,
          password_confirmation: ownerPassword,
          is_active: '1',
        },
        'e2eapilong',
      ],
      [
        'VAL-006',
        'password mismatch',
        {
          username: `e2eapipass${stamp}`,
          full_name: 'Bad Password',
          email: `e2eapipass${stamp}@e2e.test`,
          role: 'requester',
          password: ownerPassword,
          password_confirmation: 'different-secret',
          is_active: '1',
        },
        'e2eapipass',
      ],
    ];

    for (const [id, title, payload, usernamePrefix] of invalidUsers) {
      await runner.run(
        { id, priority: 'P1', category: 'validation', title: `admin user validation rejects ${title}` },
        async () => {
          const before = mysqlInt(`SELECT COUNT(*) FROM users WHERE username LIKE ${sqlString(`${usernamePrefix}%`)}`);
          const response = await runner.send(admin.context, 'POST', '/admin/users', {
            form: { _csrf: admin.csrf, ...payload },
          });
          expect(response.status).toBe(302);
          expect(locationOf(response)).toBe('/admin');
          expect(mysqlInt(`SELECT COUNT(*) FROM users WHERE username LIKE ${sqlString(`${usernamePrefix}%`)}`)).toBe(
            before
          );
        }
      );
    }

    await runner.run(
      { id: 'VAL-007', priority: 'P1', category: 'validation', title: 'duplicate username is rejected without a second row' },
      async () => {
        const response = await runner.send(admin.context, 'POST', '/admin/users', {
          form: {
            _csrf: admin.csrf,
            username: ownerUsername,
            full_name: 'Duplicate',
            email: `duplicate-${ownerEmail}`,
            role: 'requester',
            password: ownerPassword,
            password_confirmation: ownerPassword,
            is_active: '1',
          },
        });
        expect(response.status).toBe(302);
        expect(mysqlInt(`SELECT COUNT(*) FROM users WHERE username = ${sqlString(ownerUsername)}`)).toBe(1);
      }
    );

    await runner.run(
      { id: 'AUTH-009', priority: 'P0', category: 'happy_path', title: 'newly created user can authenticate through HTTP' },
      async () => {
        owner = await loginContext(runner, ownerUsername, ownerPassword);
        contexts.push(owner.context);
        expect((await runner.send(owner.context, 'GET', '/dashboard')).status).toBe(200);
      }
    );

    await runner.run(
      { id: 'SEC-001', priority: 'P0', category: 'security', title: 'profile mass-assignment fields cannot elevate role or change id' },
      async () => {
        const row = mysqlRows(
          `SELECT full_name,email,phone,version FROM users WHERE id = ${ownerUserId}`
        )[0];
        const response = await runner.send(owner.context, 'POST', '/profile', {
          form: {
            _csrf: owner.csrf,
            full_name: row[0],
            email: row[1],
            phone: row[2],
            original_version: row[3],
            role: 'admin',
            is_active: '1',
            id: '4',
          },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/profile');
        expect(mysqlRows(`SELECT id,role,is_active FROM users WHERE id = ${ownerUserId}`)).toEqual([
          [String(ownerUserId), 'requester', '1'],
        ]);
      }
    );

    await runner.run(
      { id: 'TICKET-001', priority: 'P0', category: 'happy_path', title: 'requester creates a ticket and server ignores trusted-field spoofing' },
      async () => {
        primaryTicketToken = uniqueHex();
        const response = await runner.send(owner.context, 'POST', '/tickets', {
          form: {
            _csrf: owner.csrf,
            ...ticketForm(stamp, {
              submission_token: primaryTicketToken,
              channel: 'email',
              requester_id: '4',
              status: 'completed',
            }),
          },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toMatch(/^\/tickets\/\d+$/);
        primaryTicketId = Number(locationOf(response).match(/\d+$/)?.[0] ?? 0);
        trackedTicketIds.push(primaryTicketId);
        expect(
          mysqlRows(
            `SELECT requester_id,status,channel,submission_token FROM tickets WHERE id = ${primaryTicketId}`
          )
        ).toEqual([[String(ownerUserId), 'pending_approval', 'web', primaryTicketToken]]);
      }
    );

    const invalidTickets: Array<[string, string, Record<string, string>]> = [
      ['TICKET-002', 'missing title', { title: '' }],
      ['TICKET-003', 'malformed reference id', { priority_id: '1junk' }],
      ['TICKET-004', 'title over 200 characters', { title: 'T'.repeat(201) }],
      ['TICKET-005', 'invalid severity', { impact_level: 'catastrophic' }],
    ];
    for (const [id, title, overrides] of invalidTickets) {
      await runner.run(
        { id, priority: 'P1', category: 'validation', title: `ticket validation rejects ${title}` },
        async () => {
          const marker = `${stamp}-${id}`;
          const before = mysqlInt(`SELECT COUNT(*) FROM tickets WHERE title LIKE ${sqlString(`%${marker}%`)}`);
          const response = await runner.send(owner.context, 'POST', '/tickets', {
            form: {
              _csrf: owner.csrf,
              ...ticketForm(marker, { ...overrides, submission_token: uniqueHex() }),
            },
          });
          expect(response.status).toBe(302);
          expect(locationOf(response)).toBe('/tickets/create');
          expect(mysqlInt(`SELECT COUNT(*) FROM tickets WHERE title LIKE ${sqlString(`%${marker}%`)}`)).toBe(before);
        }
      );
    }

    await runner.run(
      { id: 'TICKET-006', priority: 'P0', category: 'edge', title: 'duplicate submit with the same token is idempotent' },
      async () => {
        const duplicate = await runner.send(owner.context, 'POST', '/tickets', {
          form: {
            _csrf: owner.csrf,
            ...ticketForm(stamp, { submission_token: primaryTicketToken }),
          },
        });
        expect(duplicate.status).toBe(302);
        expect(locationOf(duplicate)).toBe(`/tickets/${primaryTicketId}`);
        expect(mysqlInt(`SELECT COUNT(*) FROM tickets WHERE submission_token = ${sqlString(primaryTicketToken)}`)).toBe(1);
      }
    );

    await runner.run(
      { id: 'TICKET-007', priority: 'P1', category: 'security', title: 'stored XSS payload is escaped in the HTTP response' },
      async () => {
        const rawTitle = `<script>alert(1)</script> E2E-API-${stamp}-XSS`;
        const response = await runner.send(owner.context, 'POST', '/tickets', {
          form: {
            _csrf: owner.csrf,
            ...ticketForm(`${stamp}-XSS`, {
              title: rawTitle,
              description: '<img src=x onerror=alert(2)>',
              submission_token: uniqueHex(),
            }),
          },
        });
        expect(response.status).toBe(302);
        const id = Number(locationOf(response).match(/\d+$/)?.[0] ?? 0);
        trackedTicketIds.push(id);
        const show = await runner.send(owner.context, 'GET', `/tickets/${id}`);
        expect(show.status).toBe(200);
        expect(show.body).not.toContain(rawTitle);
        expect(show.body).not.toContain('<img src=x onerror=alert(2)>');
        expect(show.body).toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
      }
    );

    await runner.run(
      { id: 'READ-001', priority: 'P1', category: 'read_only', title: 'visible ticket state has the documented JSON types' },
      async () => {
        const response = await runner.send(owner.context, 'GET', `/tickets/${primaryTicketId}/state`, {
          headers: { Accept: 'application/json' },
        });
        expect(response.status).toBe(200);
        expect(response.headers['content-type']).toContain('application/json');
        const json = assertJsonObject(response.body);
        expect(typeof json.status).toBe('string');
        expect(typeof json.comment_count).toBe('number');
      }
    );

    await runner.run(
      { id: 'IDOR-001', priority: 'P0', category: 'security', title: 'another requester cannot open an owner ticket' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', `/tickets/${primaryTicketId}`);
        expect(response.status).toBe(404);
      }
    );

    await runner.run(
      { id: 'IDOR-002', priority: 'P0', category: 'security', title: 'another requester cannot read an owner ticket state JSON' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', `/tickets/${primaryTicketId}/state`, {
          headers: { Accept: 'application/json' },
        });
        expect(response.status).toBe(404);
        expect(assertJsonObject(response.body)).toEqual({ error: 'not_found' });
      }
    );

    await runner.run(
      { id: 'ROLE-005', priority: 'P0', category: 'role_permission', title: 'unassigned technician cannot approve or change ticket state' },
      async () => {
        const before = mysqlValue(`SELECT status FROM tickets WHERE id = ${primaryTicketId}`);
        const response = await runner.send(technician.context, 'POST', `/tickets/${primaryTicketId}/approve`, {
          form: { _csrf: technician.csrf, note: 'bypass attempt' },
        });
        expect(response.status).toBe(302);
        expect(mysqlValue(`SELECT status FROM tickets WHERE id = ${primaryTicketId}`)).toBe(before);
      }
    );

    await runner.run(
      { id: 'COMMENT-001', priority: 'P0', category: 'happy_path', title: 'owner creates a comment through HTTP' },
      async () => {
        const token = uniqueHex();
        const response = await runner.send(owner.context, 'POST', `/tickets/${primaryTicketId}/comments`, {
          form: {
            _csrf: owner.csrf,
            body: 'E2E-API comment original',
            is_internal: '0',
            submission_token: token,
          },
        });
        expect(response.status).toBe(302);
        commentId = Number(
          mysqlValue(
            `SELECT id FROM ticket_comments WHERE ticket_id = ${primaryTicketId} AND submission_token = ${sqlString(token)}`
          )
        );
        expect(commentId).toBeGreaterThan(0);
      }
    );

    await runner.run(
      { id: 'API-001', priority: 'P0', category: 'happy_path', title: 'AJAX comment update returns the success JSON contract' },
      async () => {
        const version = mysqlValue(`SELECT version FROM ticket_comments WHERE id = ${commentId}`);
        const response = await runner.send(owner.context, 'POST', `/tickets/${primaryTicketId}/comments/${commentId}/update`, {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          form: {
            _csrf: owner.csrf,
            body: 'E2E-API comment updated',
            original_version: version,
          },
        });
        expect(response.status).toBe(200);
        expect(response.headers['content-type']).toContain('application/json');
        const json = assertJsonObject(response.body);
        expect(json.success).toBe(true);
        expect(typeof json.message).toBe('string');
        expect(typeof json.comment).toBe('object');
        expect((json.comment as Record<string, unknown>).body).toBe('E2E-API comment updated');
      }
    );

    await runner.run(
      { id: 'API-002', priority: 'P0', category: 'csrf_method', title: 'AJAX comment update rejects invalid CSRF with 422 JSON' },
      async () => {
        const before = mysqlValue(`SELECT body FROM ticket_comments WHERE id = ${commentId}`);
        const response = await runner.send(owner.context, 'POST', `/tickets/${primaryTicketId}/comments/${commentId}/update`, {
          headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
          form: { _csrf: 'invalid', body: 'must not persist', original_version: '2' },
        });
        expect(response.status).toBe(422);
        expect(response.headers['content-type']).toContain('application/json');
        const json = assertJsonObject(response.body);
        expect(json.success).toBe(false);
        expect(typeof json.message).toBe('string');
        expect(mysqlValue(`SELECT body FROM ticket_comments WHERE id = ${commentId}`)).toBe(before);
      }
    );

    await runner.run(
      { id: 'API-003', priority: 'P1', category: 'csrf_method', title: 'malformed JSON cannot bypass a form-only mutation endpoint' },
      async () => {
        const before = mysqlValue(`SELECT body FROM ticket_comments WHERE id = ${commentId}`);
        const response = await runner.send(owner.context, 'POST', `/tickets/${primaryTicketId}/comments/${commentId}/update`, {
          headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
          },
          data: '{"_csrf":',
          logBody: '[malformed JSON]',
        });
        expect(response.status).toBe(422);
        expect(assertJsonObject(response.body).success).toBe(false);
        expect(mysqlValue(`SELECT body FROM ticket_comments WHERE id = ${commentId}`)).toBe(before);
      }
    );

    await runner.run(
      { id: 'READ-002', priority: 'P1', category: 'read_only', title: 'authenticated notification feed has stable JSON field types' },
      async () => {
        const response = await runner.send(owner.context, 'GET', '/notifications/feed', {
          headers: { Accept: 'application/json' },
        });
        expect(response.status).toBe(200);
        const json = assertJsonObject(response.body);
        expect(typeof json.unreadCount).toBe('number');
        expect(typeof json.actionCount).toBe('number');
        expect(Array.isArray(json.items)).toBe(true);
      }
    );

    await runner.run(
      { id: 'UPLOAD-001', priority: 'P0', category: 'happy_path', title: 'multipart text attachment reaches DB/disk and downloads byte-for-byte' },
      async () => {
        validAttachmentContent = `E2E API attachment ${stamp}\n`;
        const multipart = {
          _csrf: owner.csrf,
          ...ticketForm(`${stamp}-UPLOAD`, { submission_token: uniqueHex() }),
          'attachments[]': {
            name: `e2e-api-${stamp}.txt`,
            mimeType: 'text/plain',
            buffer: Buffer.from(validAttachmentContent),
          },
        };
        const response = await runner.send(owner.context, 'POST', '/tickets', {
          multipart,
          logBody: { ...multipart, 'attachments[]': { name: `e2e-api-${stamp}.txt`, size: Buffer.byteLength(validAttachmentContent) } },
        });
        expect(response.status).toBe(302);
        const id = Number(locationOf(response).match(/\d+$/)?.[0] ?? 0);
        trackedTicketIds.push(id);
        const row = mysqlRows(
          `SELECT id,disk_path,mime_type,file_size FROM ticket_attachments WHERE ticket_id = ${id}`
        )[0];
        validAttachmentId = Number(row[0]);
        expect(row[2]).toBe('text/plain');
        expect(Number(row[3])).toBe(Buffer.byteLength(validAttachmentContent));
        expect(fs.readFileSync(path.resolve(ROOT, row[1]), 'utf8')).toBe(validAttachmentContent);
        const download = await runner.send(owner.context, 'GET', `/attachments/${validAttachmentId}`);
        expect(download.status).toBe(200);
        expect(download.body).toBe(validAttachmentContent);
      }
    );

    await runner.run(
      { id: 'IDOR-003', priority: 'P0', category: 'security', title: 'another requester cannot download an owner attachment' },
      async () => {
        const response = await runner.send(requesterUser.context, 'GET', `/attachments/${validAttachmentId}`);
        expect(response.status).toBe(404);
        expect(response.body).not.toContain(validAttachmentContent);
      }
    );

    const uploadRejectCases: Array<[string, string, () => FormData]> = [
      [
        'UPLOAD-002',
        'unsupported binary MIME',
        () => {
          const form = new FormData();
          Object.entries({ _csrf: owner.csrf, ...ticketForm(`${stamp}-BINARY`) }).forEach(([key, value]) =>
            form.append(key, value)
          );
          form.append('attachments[]', new Blob([Buffer.from([0, 1, 2, 3, 4, 5])]), 'payload.php');
          return form;
        },
      ],
      [
        'UPLOAD-003',
        'file larger than 5MB',
        () => {
          const form = new FormData();
          Object.entries({ _csrf: owner.csrf, ...ticketForm(`${stamp}-LARGE`) }).forEach(([key, value]) =>
            form.append(key, value)
          );
          form.append('attachments[]', new Blob([Buffer.alloc(5 * 1024 * 1024 + 1, 65)], { type: 'text/plain' }), 'large.txt');
          return form;
        },
      ],
      [
        'UPLOAD-004',
        'more than three files',
        () => {
          const form = new FormData();
          Object.entries({ _csrf: owner.csrf, ...ticketForm(`${stamp}-COUNT`) }).forEach(([key, value]) =>
            form.append(key, value)
          );
          for (let index = 1; index <= 4; index += 1) {
            form.append('attachments[]', new Blob([`file ${index}`], { type: 'text/plain' }), `file-${index}.txt`);
          }
          return form;
        },
      ],
    ];

    for (const [id, title, multipartFactory] of uploadRejectCases) {
      await runner.run(
        { id, priority: 'P1', category: 'validation', title: `upload validation rejects ${title}` },
        async () => {
          const marker = `${stamp}-${id.replace('UPLOAD-', '')}`;
          const before = mysqlInt(`SELECT COUNT(*) FROM tickets WHERE title LIKE ${sqlString(`%${marker}%`)}`);
          const response = await runner.send(owner.context, 'POST', '/tickets', {
            multipart: multipartFactory(),
            logBody: { multipart: title, secrets: '[MASKED]' },
          });
          expect(response.status).toBe(302);
          expect(locationOf(response)).toBe('/tickets/create');
          expect(mysqlInt(`SELECT COUNT(*) FROM tickets WHERE title LIKE ${sqlString(`%${marker}%`)}`)).toBe(before);
        }
      );
    }

    await runner.run(
      { id: 'ASSET-001', priority: 'P0', category: 'happy_path', title: 'manager creates an asset and QR row through HTTP' },
      async () => {
        const payload = assetForm(stamp);
        const response = await runner.send(manager.context, 'POST', '/asset-registry', {
          form: { _csrf: manager.csrf, ...payload },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toMatch(/^\/asset-registry\/\d+$/);
        assetId = Number(locationOf(response).match(/\d+$/)?.[0] ?? 0);
        trackedAssetIds.push(assetId);
        expect(
          mysqlRows(
            `SELECT a.asset_code,a.status,COUNT(q.id) FROM assets a LEFT JOIN asset_qr_tokens q ON q.asset_id=a.id ` +
              `WHERE a.id=${assetId} GROUP BY a.id`
          )
        ).toEqual([[payload.asset_code, 'active', '1']]);
      }
    );

    await runner.run(
      { id: 'ASSET-002', priority: 'P1', category: 'validation', title: 'asset validation rejects an unknown status' },
      async () => {
        const payload = assetForm(`${stamp}-BAD`, { status: 'unknown' });
        const response = await runner.send(manager.context, 'POST', '/asset-registry', {
          form: { _csrf: manager.csrf, ...payload },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/asset-registry/create');
        expect(mysqlInt(`SELECT COUNT(*) FROM assets WHERE asset_code = ${sqlString(payload.asset_code)}`)).toBe(0);
      }
    );

    await runner.run(
      { id: 'ASSET-003', priority: 'P0', category: 'role_permission', title: 'requester cannot create an asset' },
      async () => {
        const payload = assetForm(`${stamp}-ROLE`);
        const response = await runner.send(requesterUser.context, 'POST', '/asset-registry', {
          form: { _csrf: requesterUser.csrf, ...payload },
        });
        expect(response.status).toBe(302);
        expect(mysqlInt(`SELECT COUNT(*) FROM assets WHERE asset_code = ${sqlString(payload.asset_code)}`)).toBe(0);
      }
    );

    await runner.run(
      { id: 'EDGE-001', priority: 'P1', category: 'edge', title: 'non-existing ticket state returns typed 404 JSON' },
      async () => {
        const response = await runner.send(admin.context, 'GET', '/tickets/999999999/state', {
          headers: { Accept: 'application/json' },
        });
        expect(response.status).toBe(404);
        expect(assertJsonObject(response.body)).toEqual({ error: 'not_found' });
      }
    );

    await runner.run(
      { id: 'AUTH-010', priority: 'P0', category: 'csrf_method', title: 'logout without CSRF does not end the session' },
      async () => {
        const context = await loginContext(runner, accounts.requester.login, accounts.requester.password);
        contexts.push(context.context);
        const response = await runner.send(context.context, 'POST', '/logout', { form: {} });
        expect(response.status).toBe(302);
        expect((await runner.send(context.context, 'GET', '/dashboard')).status).toBe(200);
      }
    );

    await runner.run(
      { id: 'AUTH-011', priority: 'P0', category: 'authentication', title: 'valid logout clears authentication' },
      async () => {
        const context = await loginContext(runner, accounts.requester.login, accounts.requester.password);
        contexts.push(context.context);
        const response = await runner.send(context.context, 'POST', '/logout', {
          form: { _csrf: context.csrf },
        });
        expect(response.status).toBe(302);
        expect(locationOf(response)).toBe('/login');
        expect((await runner.send(context.context, 'GET', '/dashboard')).status).toBe(302);
      }
    );

    await runner.run(
      { id: 'RATE-001', priority: 'P1', category: 'edge', title: 'sixth failed login is rate-limited and auditable' },
      async () => {
        const context = await request.newContext({ baseURL: BASE_URL });
        contexts.push(context);
        const csrf = await csrfFrom(runner, context, '/login');
        const loginName = `e2eapi-rate-${stamp}`;
        let response!: Captured;
        for (let attempt = 1; attempt <= 6; attempt += 1) {
          response = await runner.send(context, 'POST', '/login', {
            form: {
              _csrf: csrf,
              login: loginName,
              password: 'wrong-secret',
              return_to: '/dashboard',
            },
          });
          expect(response.status).toBe(302);
        }
        expect(
          mysqlValue(
            `SELECT failure_reason FROM login_attempts WHERE attempted_login = ${sqlString(loginName)} ORDER BY id DESC LIMIT 1`
          )
        ).toBe('rate_limited');
        const flashPage = await runner.send(context, 'GET', locationOf(response));
        expect(flashPage.body).toContain('พยายามเข้าสู่ระบบเกินกำหนด');
      }
    );

    await runner.run(
      { id: 'CLEAN-001', priority: 'P0', category: 'cleanup', title: 'state-changing test data is removed and resources return 404' },
      async () => {
        const verifyTicketId = primaryTicketId;
        const verifyAssetId = assetId;
        cleanup();
        expect(mysqlInt("SELECT COUNT(*) FROM users WHERE username LIKE 'e2eapi%' OR email LIKE '%@e2e.test'")).toBe(0);
        expect(mysqlInt("SELECT COUNT(*) FROM tickets WHERE title LIKE '%E2E-API-%'")).toBe(0);
        expect(mysqlInt("SELECT COUNT(*) FROM assets WHERE asset_code LIKE 'E2E-API-%'")).toBe(0);
        expect((await runner.send(admin.context, 'GET', `/tickets/${verifyTicketId}`)).status).toBe(404);
        expect((await runner.send(admin.context, 'GET', `/asset-registry/${verifyAssetId}`)).status).toBe(404);
      }
    );
  } finally {
    cleanup();
    for (const context of contexts) {
      await context.dispose();
    }
  }

  expect(runner.failures, runner.failures.join('\n')).toEqual([]);
});
