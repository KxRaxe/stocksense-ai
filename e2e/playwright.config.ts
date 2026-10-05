import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests run against the production-like stack (compose.prod.yaml with
 * e2e/compose.e2e.yaml), which serves built assets, so pages load fast and what is
 * tested is what would be deployed. See README.md for how to bring it up.
 */
export default defineConfig({
    testDir: './tests',
    // The tests share one database and one forecast run, and some depend on what earlier ones did.
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: process.env.CI
        ? [['list'], ['html', { open: 'never' }], ['github']]
        : [['list'], ['html', { open: 'never' }]],
    globalSetup: './global-setup.ts',
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8090',
        // The app marks elements for tests with `data-test`, not `data-testid`.
        testIdAttribute: 'data-test',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
        viewport: { width: 1280, height: 800 },
        locale: 'en-PH',
        timezoneId: 'Asia/Manila',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
