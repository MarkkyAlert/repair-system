import fs from 'node:fs';
import path from 'node:path';

// The guest submit/lookup flows are rate-limited per IP (3–10 per 10 min). Since every E2E run hits
// them from 127.0.0.1, repeated runs within the window would otherwise start blocked. Clear just the
// guest_* keys from the shared throttle store before each run (login throttle keys are left alone).
//
// NOTE: we no longer force `setup_completed` here — the seed sets it and, more importantly, the
// app's setup gate now treats "an active admin exists" as set-up (SetupController::requiresSetupRedirect),
// so a seeded DB reaches the app without any workaround.
function resetGuestRateLimit(): void {
  const rateFile = path.resolve(__dirname, '..', 'storage', 'logs', 'login_rate_limits.json');
  try {
    if (!fs.existsSync(rateFile)) return;
    const data = JSON.parse(fs.readFileSync(rateFile, 'utf8')) as Record<string, unknown>;
    let changed = false;
    for (const key of Object.keys(data)) {
      if (key.startsWith('guest_')) {
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
  resetGuestRateLimit();
}
