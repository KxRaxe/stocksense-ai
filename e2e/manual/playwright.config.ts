import { defineConfig, devices } from '@playwright/test';

/**
 * Builds the student testing manual (docs/manual): screenshots of the running
 * development app, then the PDF printed from the HTML. Not a test suite.
 * Start the dev stack first (`docker compose up -d` in the repository root).
 */
export default defineConfig({
    testDir: '.',
    fullyParallel: false,
    workers: 1,
    // The first page after Vite starts can take half a minute to compile.
    timeout: 300_000,
    expect: { timeout: 60_000 },
    reporter: [['list']],
    use: {
        baseURL: process.env.MANUAL_BASE_URL ?? 'http://localhost:8080',
        testIdAttribute: 'data-test',
        viewport: { width: 1280, height: 800 },
        colorScheme: 'light',
        locale: 'en-PH',
        timezoneId: 'Asia/Manila',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } }],
});
