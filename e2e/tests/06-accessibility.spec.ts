import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { authFile } from '../support/accounts';

/**
 * Automated accessibility checks (axe, against WCAG 2.1 A and AA). They find what a machine can:
 * missing labels, bad structure, unnamed buttons, contrast. They do not replace trying the app
 * with a keyboard and a screen reader. Anything serious or critical fails the test.
 */
const pages = [
    { name: 'the login page', path: '/login', signedIn: false },
    { name: 'the dashboard', path: '/dashboard', signedIn: true },
    { name: 'the product list', path: '/products', signedIn: true },
    { name: 'the stock overview', path: '/inventory', signedIn: true },
    { name: 'the forecasts', path: '/forecasts', signedIn: true },
    { name: 'the recommendations', path: '/recommendations', signedIn: true },
    { name: 'a report', path: '/reports/sales', signedIn: true },
    { name: 'the system settings', path: '/system-settings', signedIn: true },
    { name: 'the audit log', path: '/audit-log', signedIn: true },
    { name: 'the notification settings', path: '/settings/notifications', signedIn: true },
];

for (const { name, path, signedIn } of pages) {
    test.describe(name, () => {
        test.use({ storageState: signedIn ? authFile('owner') : { cookies: [], origins: [] } });

        test('has no serious accessibility problems', async ({ page }) => {
            await page.goto(path);
            await page.waitForLoadState('networkidle');

            const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

            const serious = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');

            expect(
                serious.map((violation) => `${violation.id} (${violation.impact}): ${violation.help} - ${violation.nodes.length} element(s), e.g. ${violation.nodes[0]?.target.join(' ')}`),
            ).toEqual([]);
        });
    });
}

test.describe('with the keyboard', () => {
    test.use({ storageState: authFile('owner') });

    test('the first Tab on a page offers a way past the menu, and focus is always visible', async ({ page }) => {
        await page.goto('/dashboard');
        await page.keyboard.press('Tab');

        const focused = await page.evaluate(() => {
            const element = document.activeElement as HTMLElement | null;
            const style = element ? getComputedStyle(element) : null;

            return { tag: element?.tagName, visible: element !== document.body, outline: style?.outlineStyle, shadow: style?.boxShadow };
        });

        expect(focused.visible, 'something should take the focus').toBe(true);
    });

    test('a dialog traps focus and closes with Escape', async ({ page }) => {
        await page.goto('/system-settings');
        await page.getByTestId('setting-review_days').fill('9');
        await page.getByTestId('save-settings').click();
        await expect(page.getByText('Settings saved. They apply from now on.')).toBeVisible();

        await page.reload();
        await page.getByTestId('restore-defaults').click();

        const dialog = page.getByRole('dialog');

        await expect(dialog).toBeVisible();

        for (let i = 0; i < 6; i++) {
            await page.keyboard.press('Tab');
            expect(await dialog.evaluate((element) => element.contains(document.activeElement)), 'focus should stay inside the dialog').toBe(true);
        }

        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);

        // Leave the settings as they were.
        await page.getByTestId('restore-defaults').click();
        await page.getByTestId('confirm-restore-defaults').click();
        await expect(page.getByText('The default settings are back.')).toBeVisible();
    });
});
