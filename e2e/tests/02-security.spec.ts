import { expect, test, type Page } from '@playwright/test';
import { authFile } from '../support/accounts';

test.describe('what the server sends', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('protects every page with browser security headers', async ({ request }) => {
        const response = await request.get('/login');
        const headers = response.headers();

        expect(headers['x-frame-options']).toBe('SAMEORIGIN');
        expect(headers['x-content-type-options']).toBe('nosniff');
        expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
        expect(headers['permissions-policy']).toContain('camera=()');

        const policy = headers['content-security-policy'];

        expect(policy).toContain("default-src 'self'");
        expect(policy).toContain("script-src 'self'");
        expect(policy).not.toContain('unsafe-eval');
        expect(policy).not.toMatch(/script-src[^;]*unsafe-inline/);
    });

    test('does not say what it is built with', async ({ request }) => {
        const headers = (await request.get('/login')).headers();

        expect(headers['x-powered-by']).toBeUndefined();
        // "nginx", without a version number.
        expect(headers['server']).toBe('nginx');
    });

    test('sets session cookies that scripts cannot read, and that stay on the site', async ({ request }) => {
        const response = await request.get('/login');
        const cookies = response.headersArray().filter((header) => header.name.toLowerCase() === 'set-cookie');

        expect(cookies.length).toBeGreaterThan(0);

        for (const cookie of cookies.filter((c) => /session|XSRF/i.test(c.value.split('=')[0]))) {
            if (/session/i.test(cookie.value.split('=')[0])) {
                expect(cookie.value).toMatch(/HttpOnly/i);
            }

            expect(cookie.value).toMatch(/SameSite=Lax/i);
        }
    });

    test('serves built assets for a year, untouched', async ({ request }) => {
        const page = await (await request.get('/login')).text();
        const asset = page.match(/\/build\/assets\/[^"']+\.js/)?.[0];

        expect(asset, 'the page should load a built script').toBeTruthy();

        const headers = (await request.get(asset!)).headers();

        expect(headers['cache-control']).toContain('max-age=31536000');
        expect(headers['cache-control']).toContain('immutable');
        expect(headers['x-content-type-options']).toBe('nosniff');
    });

    test('does not serve what must not be reachable', async ({ request }) => {
        for (const path of [
            '/.env',
            '/.git/config',
            '/composer.json',
            '/artisan',
            '/vendor/autoload.php',
            '/storage/app/private/anything',
            '/phpinfo.php',
            '/info.php',
            '/index.php/../../.env',
            '/_inertia/devtools/entries',
            '/telescope',
        ]) {
            const status = (await request.get(path)).status();

            expect([403, 404], `${path} answered ${status}`).toContain(status);
        }
    });

    test('keeps the queue dashboard for the Owner', async ({ request }) => {
        const response = await request.get('/horizon', { maxRedirects: 0 });

        expect([302, 403, 404]).toContain(response.status());
    });

    test('answers the health check', async ({ request }) => {
        expect((await request.get('/up')).status()).toBe(200);
    });
});

/**
 * Collects what the browser refuses to load because of the content security policy, and
 * anything it logs as an error, while the page is used.
 */
async function watchForViolations(page: Page) {
    const found: string[] = [];

    await page.addInitScript(() => {
        (window as unknown as { __violations: string[] }).__violations = [];
        document.addEventListener('securitypolicyviolation', (event) => {
            (window as unknown as { __violations: string[] }).__violations.push(
                `${event.violatedDirective}: ${event.blockedURI}`,
            );
        });
    });

    page.on('console', (message) => {
        if (message.type() === 'error') {
            found.push(message.text());
        }
    });
    page.on('pageerror', (error) => found.push(error.message));

    return {
        found,
        violations: () => page.evaluate(() => (window as unknown as { __violations?: string[] }).__violations ?? []),
    };
}

test.describe('the public front page under the content security policy', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('loads, with its pictures, and nothing blocked', async ({ page }) => {
        const watch = await watchForViolations(page);

        await page.goto('/');
        await expect(page.getByRole('heading', { level: 1 })).toContainText('what to reorder');
        await page.waitForLoadState('networkidle');

        const pictures = await page.locator('img:visible').evaluateAll((images) => images.map((image) => (image as HTMLImageElement).naturalWidth));

        expect(pictures.length).toBeGreaterThan(0);
        expect(pictures.every((width) => width > 0), 'every picture should load').toBe(true);
        expect(await watch.violations()).toEqual([]);
    });
});

test.describe('the pages under the content security policy', () => {
    test.use({ storageState: authFile('owner') });

    test('load and work with nothing blocked', async ({ page }) => {
        const watch = await watchForViolations(page);

        for (const path of [
            '/dashboard',
            '/products',
            '/inventory',
            '/sales',
            '/forecasts',
            '/forecasts/accuracy',
            '/recommendations',
            '/reports',
            '/reports/sales',
            '/reports/inventory',
            '/notifications',
            '/audit-log',
            '/system-settings',
            '/users',
            '/settings/profile',
            '/settings/notifications',
            '/settings/appearance',
        ]) {
            await page.goto(path);
            await expect(page.locator('main').first()).toBeVisible();
            await page.waitForLoadState('networkidle');

            expect(await watch.violations(), `${path} had resources blocked`).toEqual([]);
        }

        expect(watch.found, 'the console should have no errors').toEqual([]);
    });

    test('draw the charts, which set their sizes with inline styles', async ({ page }) => {
        const watch = await watchForViolations(page);

        await page.goto('/dashboard');
        await expect(page.getByTestId('sales-trend-chart').locator('svg').first()).toBeVisible();
        await expect(page.locator('.recharts-bar-rectangle').first()).toBeVisible();

        expect(await watch.violations()).toEqual([]);
    });

    test('open dialogs and menus', async ({ page }) => {
        const watch = await watchForViolations(page);

        await page.goto('/dashboard');
        await page.getByTestId('notification-bell').click();
        await expect(page.getByText('See all notifications')).toBeVisible();
        await page.keyboard.press('Escape');

        await page.goto('/system-settings');
        await page.getByTestId('save-settings').scrollIntoViewIfNeeded();

        expect(await watch.violations()).toEqual([]);
    });
});
