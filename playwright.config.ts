import { defineConfig, devices } from '@playwright/test';
import path from 'path';

/**
 * Phase 18 E2E suite (see docs/phase-18-plan.md S6/S7).
 *
 * Isolation, per the proven verify-skill recipe:
 * - NEVER the real database: global-setup copies database/nativephp.sqlite to
 *   tests/e2e/.runtime/e2e.sqlite and seeds per-role users + fixture products
 *   there. The gapless transaction serial makes real-DB checkouts permanent.
 * - `php -S` from public/ with the framework router — NOT `artisan serve`,
 *   whose child process drops the DB_DATABASE/APP_DEBUG overrides.
 * - global-setup runs `npm run build` first: the server renders through the
 *   built Vite manifest.
 */

const E2E_DB = path.resolve(import.meta.dirname, 'tests/e2e/.runtime/e2e.sqlite');

export default defineConfig({
    testDir: 'tests/e2e',
    // One worker against one shared server + DB copy: specs mutate state
    // (checkouts) and the app enforces single-session semantics.
    workers: 1,
    fullyParallel: false,
    timeout: 30_000,
    use: {
        baseURL: 'http://127.0.0.1:8123',
        trace: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: {
        // serve.sh does ALL the isolation setup (DB copy, build, seed) before
        // exec'ing php -S — Playwright starts the webServer before globalSetup
        // would run, so setup must live in the server command itself.
        command: path.join(import.meta.dirname, 'tests/e2e/serve.sh'),
        url: 'http://127.0.0.1:8123/login',
        env: {
            DB_DATABASE: E2E_DB,
        },
        reuseExistingServer: true,
        // Covers the npm build + seed ahead of the server boot.
        timeout: 180_000,
    },
});
