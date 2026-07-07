import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

// Golden-path E2E for the repair system. The app is served by PHP's built-in server (php -S)
// against the SEEDED test DB (repair_system_test) — never the dev DB. Playwright starts/stops
// that server automatically (webServer below).

const PORT = Number(process.env.E2E_PORT || 8123);
const BASE_URL = `http://127.0.0.1:${PORT}`;

// This machine runs XAMPP; allow override via PHP_BIN for other environments.
const XAMPP_PHP = '/Applications/XAMPP/xamppfiles/bin/php';
const PHP_BIN = process.env.PHP_BIN || (fs.existsSync(XAMPP_PHP) ? XAMPP_PHP : 'php');

export default defineConfig({
  testDir: './tests',
  fullyParallel: false, // shared test DB + a stateful lifecycle test → run serially
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  globalSetup: './global-setup.ts',
  globalTeardown: './global-teardown.ts',

  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    // Logs each role in once and saves storageState (.auth/*.json) for the lifecycle test to reuse.
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    {
      name: 'e2e',
      testIgnore: /auth\.setup\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: {
    command: `${PHP_BIN} -S 127.0.0.1:${PORT} -t public e2e/router.php`,
    cwd: path.resolve(__dirname, '..'), // repo root
    url: `${BASE_URL}/login`,
    reuseExistingServer: false,
    timeout: 30_000,
    env: {
      DB_NAME: process.env.TEST_DB_NAME || 'repair_system_test',
      DB_HOST: process.env.DB_HOST || '127.0.0.1',
      DB_PORT: process.env.DB_PORT || '3306',
      DB_USERNAME: process.env.DB_USERNAME || 'root',
      DB_PASSWORD: process.env.DB_PASSWORD || '',
      APP_ENV: 'testing',
    },
  },
});
