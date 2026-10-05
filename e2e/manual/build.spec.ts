import { writeFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { expect, type Page, test } from '@playwright/test';
import { accounts, password } from '../support/accounts';

/**
 * Builds docs/manual: the screenshots the manual shows, then its PDF.
 * Run with `npm run manual` against the development stack, with the demo
 * shop loaded and at least one weekly forecast made.
 */
const manual = fileURLToPath(new URL('../../docs/manual/', import.meta.url));
const shot = (name: string) => `${manual}img/${name}.png`;

test.describe.configure({ mode: 'serial' });

async function settle(page: Page) {
    await page.waitForLoadState('networkidle');
    // Let entrance animations and chart drawing finish.
    await page.waitForTimeout(800);
}

async function useTheme(page: Page, mode: 'light' | 'dark') {
    await page.context().addCookies([{ name: 'appearance', value: mode, url: test.info().project.use.baseURL! }]);
    await page.addInitScript((value) => localStorage.setItem('appearance', value), mode);
}

test('screenshots', async ({ page }) => {
    await useTheme(page, 'light');

    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await settle(page);
    await page.screenshot({ path: shot('00-landing') });

    await page.goto('/login');
    await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    await settle(page);
    await page.screenshot({ path: shot('01-login') });

    await page.getByLabel('Email address').fill(accounts.owner.email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await settle(page);
    await page.screenshot({ path: shot('02-dashboard') });

    await page.getByRole('button', { name: 'Switch to dark mode' }).click();
    await settle(page);
    await page.screenshot({ path: shot('03-dashboard-dark') });
    await page.getByRole('button', { name: 'Switch to light mode' }).click();

    await page.goto('/products/1');
    await settle(page);
    await page.screenshot({ path: shot('05-product') });

    // An import preview, cancelled afterwards so it leaves nothing behind.
    await page.goto('/sales/imports/create');
    await page.locator('input[type="file"]').setInputFiles('fixtures/sales-with-problems.csv');
    await page.getByTestId('upload-button').click();
    await expect(page.getByTestId('start-import-button')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: shot('07-import-preview') });
    await page.getByTestId('cancel-import-button').click();
    await expect(page.getByTestId('start-import-button')).toHaveCount(0);

    await page.goto('/forecasts');
    await expect(page.getByTestId('run-summary')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: shot('09-forecasts') });

    await page.goto('/recommendations');
    await settle(page);
    await page.screenshot({ path: shot('10-recommendations') });

    await page.goto('/reports/sales');
    await expect(page.getByTestId('report-summary')).toBeVisible();
    await settle(page);
    await page.screenshot({ path: shot('12-report') });
});

test('pdf', async ({ page }) => {
    await page.emulateMedia({ media: 'print', colorScheme: 'light' });
    await page.goto(pathToFileURL(`${manual}testing-manual.html`).href);
    await page.waitForLoadState('load');

    const pdf = await page.pdf({
        format: 'A4',
        printBackground: true,
        preferCSSPageSize: true,
        displayHeaderFooter: true,
        headerTemplate: '<span></span>',
        footerTemplate:
            '<div style="width:100%;font:8px Consolas,monospace;color:#535769;padding:0 13mm;display:flex;justify-content:space-between"><span>StockSense AI · Student testing manual</span><span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>',
    });

    await writeFile(`${manual}StockSense-AI-Testing-Manual.pdf`, pdf);
});
