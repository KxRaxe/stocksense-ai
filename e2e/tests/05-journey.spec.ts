import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { authFile } from '../support/accounts';
import { allMail, clearMail, waitForMail } from '../support/mailpit';

/**
 * The thesis journey, start to finish, as the Owner: bring in sales, forecast them, act on the
 * advice, record the goods arriving, export a report, change a setting, and see it all in the
 * audit log and the inbox. Each step depends on the one before, so they run in order.
 */
test.describe.configure({ mode: 'serial' });
test.use({ storageState: authFile('owner') });

test.describe('from sales to a decision', () => {
    let orderedProduct = { href: '', name: '', quantity: 0 };

    test.beforeAll(async () => {
        await clearMail();
    });

    test('imports a file of sales, with a preview first', async ({ page }) => {
        await page.goto('/sales/imports/create');
        await page.locator('input[type="file"]').setInputFiles('fixtures/sales-import.csv');
        await page.getByTestId('upload-button').click();

        // A preview, before anything is saved.
        await expect(page.getByTestId('start-import-button')).toBeVisible();
        await expect(page).toHaveURL(/\/sales\/imports\/\d+$/);
        await expect(page.getByText('FB-006').first()).toBeVisible();

        await page.getByTestId('start-import-button').click();

        await expect(page.getByTestId('view-import-results')).toBeVisible({ timeout: 90_000 });
        await expect(page.getByText('5', { exact: true }).first()).toBeVisible();
    });

    test('shows the imported sales in the sales list', async ({ page }) => {
        await page.goto('/sales');

        await expect(page.locator('main')).toContainText('FB-006');
        await expect(page.locator('main')).toContainText('2026-10-01'.replace(/^(\d{4})-(\d{2})-(\d{2})$/, (_, y, m, d) => `Oct ${Number(d)}, ${y}`));
    });

    test('a file with problems says what is wrong and offers the report, importing only what is good', async ({ page }) => {
        await page.goto('/sales/imports/create');
        await page.locator('input[type="file"]').setInputFiles('fixtures/sales-with-problems.csv');
        await page.getByTestId('upload-button').click();

        await expect(page.getByTestId('preview-problems')).toBeVisible();

        await page.getByTestId('start-import-button').click();

        await expect(page.getByTestId('view-import-results')).toBeVisible({ timeout: 90_000 });
        await expect(page.getByTestId('download-error-report')).toBeVisible();

        const [download] = await Promise.all([page.waitForEvent('download'), page.getByTestId('download-error-report').click()]);
        const report = (await readFile((await download.path())!)).toString('utf-8');

        expect(report).toContain('NOPE-999');
    });

    test('runs a forecast and shows how accurate it has been', async ({ page }) => {
        await page.goto('/forecasts');
        await page.getByTestId('run-forecast-button').click();

        await expect(page.getByTestId('run-progress')).toBeVisible();
        // Training a model takes a minute or two.
        await expect(page.getByTestId('run-summary')).toBeVisible({ timeout: 300_000 });
        await expect(page.getByTestId('run-summary')).toContainText('Typically off by');
        await expect(page.getByTestId('run-summary')).toContainText('50 products');

        await expect(page.getByTestId(/^forecast-row-/).first()).toBeVisible();

        await page.getByTestId('see-accuracy').click();
        await expect(page.getByRole('heading', { name: /accuracy/i }).first()).toBeVisible();
    });

    test('opens a product\'s forecast, with its chart', async ({ page }) => {
        await page.goto('/forecasts');
        await page.getByTestId(/^forecast-row-/).first().getByRole('link').first().click();

        await expect(page.getByTestId('forecast-chart')).toBeVisible();
    });

    test('has recommendations ready once the forecast is made, with the reasoning', async ({ page }) => {
        // Advice is worked out after every forecast, on the queue.
        await expect
            .poll(
                async () => {
                    await page.goto('/recommendations');

                    return page.getByTestId('recommendation-list').count();
                },
                { timeout: 120_000, intervals: [3_000] },
            )
            .toBeGreaterThan(0);

        const first = page.getByTestId(/^recommendation-\d+$/).first();

        await expect(first.getByTestId('explanation')).not.toBeEmpty();
        await expect(first.getByTestId('order-summary')).toContainText('Order');

        const critical = Number((await page.getByTestId('count-critical').innerText()).split('\n')[0].replace(/,/g, ''));

        expect(critical).toBeGreaterThan(0);
    });

    test('accepts a recommendation, which then counts as on order', async ({ page }) => {
        await page.goto('/recommendations');

        const card = page.getByTestId(/^recommendation-\d+$/).first();

        orderedProduct.name = (await card.getByRole('link').first().innerText()).trim();
        orderedProduct.href = (await card.getByRole('link').first().getAttribute('href'))!;
        orderedProduct.quantity = Number((await card.getByTestId('order-summary').innerText()).match(/Order ([\d,]+)/)![1].replace(/,/g, ''));

        await card.getByTestId('accept-button').click();
        await page.getByLabel('Note (optional)').fill('Ordered by phone (end-to-end test)');
        await page.getByTestId('confirm-decision-button').click();

        await expect(page.getByText('Accepted. The quantity now counts as on order.')).toBeVisible();
        await expect(page.getByTestId('count-ordered')).toContainText('1');

        await page.getByTestId('view-decided').click();
        const decided = page.getByTestId(/^recommendation-\d+$/).first();

        await expect(decided).toContainText(orderedProduct.name);
        await expect(decided.getByTestId('order-summary')).toContainText(`${orderedProduct.quantity.toLocaleString('en-US')}`);
        await expect(decided.getByTestId('decision-summary')).toContainText('Accepted');
        await expect(decided.getByTestId('decision-summary')).toContainText('Ordered by phone (end-to-end test)');
    });

    test('shows the on-order quantity on the product, and it clears when the goods arrive', async ({ page }) => {
        await page.goto(orderedProduct.href);

        await expect(page.locator('main')).toContainText('On order');
        await expect(page.locator('main')).toContainText(orderedProduct.quantity.toLocaleString('en-US'));

        await page.getByTestId('restock-button').click();
        await page.getByLabel(/Quantity received/).fill(String(orderedProduct.quantity));
        await page.getByTestId('confirm-restock-button').click();

        await expect(page.getByText('Restock recorded.')).toBeVisible();

        // On order is whatever has not arrived yet: nothing now.
        const onOrder = page.locator('main').getByText('On order').locator('xpath=following-sibling::*[1]');

        await expect(onOrder).toHaveText('0');
    });

    test('exports a report as Excel and as PDF', async ({ page }) => {
        await page.goto('/reports/sales');
        await expect(page.getByTestId('report-summary')).toBeVisible();

        const [excel] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-xlsx').click()]);

        expect(excel.suggestedFilename()).toMatch(/^stocksense-sales-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xlsx$/);
        expect((await readFile((await excel.path())!)).subarray(0, 2).toString()).toBe('PK');

        const [pdf] = await Promise.all([page.waitForEvent('download'), page.getByTestId('export-pdf').click()]);

        expect(pdf.suggestedFilename()).toMatch(/^stocksense-sales-.*\.pdf$/);

        const bytes = await readFile((await pdf.path())!);

        expect(bytes.subarray(0, 5).toString()).toBe('%PDF-');
        expect(bytes.length).toBeGreaterThan(5_000);
    });

    test('filters a report, and carries the filter into its exports', async ({ page }) => {
        await page.goto('/reports/sales');
        await page.getByTestId('filter-category').selectOption({ index: 1 });
        await page.getByTestId('apply-filters').click();

        await expect(page).toHaveURL(/category=\d+/);
        await expect(page.getByTestId('export-xlsx')).toHaveAttribute('href', /category=\d+/);
    });

    test('changes a setting, which sticks, and puts it back', async ({ page }) => {
        await page.goto('/system-settings');
        await page.getByTestId('setting-review_days').fill('14');
        await page.getByTestId('save-settings').click();

        await expect(page.getByText('Settings saved. They apply from now on.')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('setting-review_days')).toHaveValue('14');
        await expect(page.getByTestId('field-review_days').getByTestId('default-note')).toContainText('Default: 7 days.');

        await page.getByTestId('restore-defaults').click();
        await page.getByTestId('confirm-restore-defaults').click();
        await expect(page.getByText('The default settings are back.')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('setting-review_days')).toHaveValue('7');
    });

    test('keeps a record of all of it in the audit log', async ({ page }) => {
        await page.goto('/audit-log');

        const table = page.getByTestId('audit-table');

        for (const text of [
            'Sales import started',
            'Weekly forecast started',
            `accepted for ${orderedProduct.quantity}`,
            'Exported the sales report as an Excel file',
            'Exported the sales report as a PDF',
            'Changed the system settings',
            'Restored the default system settings',
        ]) {
            await expect(table, `the log should say "${text}"`).toContainText(text);
        }

        await page.getByTestId('audit-search').fill('settings');
        await expect(page.getByTestId(/^audit-entry-/)).toHaveCount(2);
    });

    test('tells the people concerned, in the app and by email, with nothing personal in the email', async ({ page }) => {
        await page.goto('/notifications');
        await expect(page.getByTestId('notification-row').first()).toBeVisible();

        const mail = await waitForMail('Critical stock');
        const body = [mail.Subject, mail.Text ?? '', mail.HTML ?? ''].join('\n').toLowerCase();

        for (const personal of ['demo owner', 'demo manager', 'demo inventory', '@stocksense.test', 'owner@', 'manager@']) {
            expect(body, `the email should not contain "${personal}"`).not.toContain(personal);
        }

        expect(body).toMatch(/hello[,!]/);

        // Only the people who decide are told about stock: the Owner and the Manager.
        const recipients = (await allMail()).filter((m) => m.Subject.includes('Critical stock')).flatMap((m) => m.To.map((to) => to.Address));

        expect(recipients.sort()).toEqual(['manager@stocksense.test', 'owner@stocksense.test']);
    });

    test('the Owner chooses what comes by email, and it is respected', async ({ page }) => {
        await page.goto('/settings/notifications');
        await expect(page.getByTestId('setting-critical_stock')).toBeVisible();

        await page.getByTestId('critical_stock-mail').click();
        await page.getByTestId('save-settings').click();
        await expect(page.getByText('Notification settings saved.')).toBeVisible();

        await page.reload();
        await expect(page.getByTestId('critical_stock-mail')).toHaveAttribute('aria-checked', 'false');
        await expect(page.getByTestId('critical_stock-database')).toHaveAttribute('aria-checked', 'true');

        // Put it back.
        await page.getByTestId('critical_stock-mail').click();
        await page.getByTestId('save-settings').click();
        await expect(page.getByText('Notification settings saved.')).toBeVisible();
    });
});
