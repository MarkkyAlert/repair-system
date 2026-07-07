import { cleanupE2E } from './helpers/db';

// Runs once after the whole suite: remove E2E-created rows from the test DB so the next run
// (and the PHP suite that shares repair_system_test) starts clean.
export default async function globalTeardown(): Promise<void> {
  try {
    cleanupE2E();
  } catch (err) {
    // Don't fail the run on cleanup issues, but make them visible.
    console.warn('[e2e] cleanup failed:', (err as Error).message);
  }
}
