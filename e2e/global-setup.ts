import fs from 'node:fs';
import path from 'node:path';
import { captureE2EBaseline } from './helpers/db';

// Guest submit/lookup and password reset are rate-limited per IP. Since every E2E run hits them from
// 127.0.0.1, repeated local runs within the window would otherwise start blocked. Clear only those
// flow-specific keys from the shared throttle store (ordinary login throttle keys are left alone).
//
// NOTE: we no longer force `setup_completed` here — the seed sets it and, more importantly, the
// app's setup gate now treats "an active admin exists" as set-up (SetupController::requiresSetupRedirect),
// so a seeded DB reaches the app without any workaround.
export function resetE2ERateLimits(): void {
  const rateFile = path.resolve(__dirname, '..', 'storage', 'logs', 'login_rate_limits.json');
  try {
    if (!fs.existsSync(rateFile)) return;
    const data = JSON.parse(fs.readFileSync(rateFile, 'utf8')) as Record<string, unknown>;
    let changed = false;
    for (const key of Object.keys(data)) {
      if (
        key.startsWith('guest_') ||
        key.startsWith('pwreset:') ||
        key.startsWith('pwreset-ip:') ||
        key.startsWith('pwreset-email:')
      ) {
        delete data[key];
        changed = true;
      }
    }
    if (changed) fs.writeFileSync(rateFile, JSON.stringify(data, null, 2));
  } catch {
    /* throttle store is best-effort; ignore */
  }
}

export default async function globalSetup(): Promise<void> {
  captureE2EBaseline();
  resetE2ERateLimits();
}
