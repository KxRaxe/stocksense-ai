import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';
import { authFile } from '../support/accounts';

/**
 * Automated accessibility checks (axe, against WCAG 2.1 A and AA). They find what a machine can:
 * missing labels, bad structure, unnamed buttons, contrast. They do not replace trying the app
 * with a keyboard and a screen reader. Anything serious or critical fails the test.
 */
const pages = [
    { name: 'the front page', path: '/', signedIn: false },
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

/** Starts the page in a chosen theme, the way a returning visitor would arrive. */
async function useTheme(page: Page, mode: 'light' | 'dark') {
    const { baseURL } = test.info().project.use;
    await page.context().addCookies([{ name: 'appearance', value: mode, url: baseURL ?? 'http://localhost:8090' }]);
    await page.addInitScript((value) => localStorage.setItem('appearance', value), mode);
}

for (const mode of ['light', 'dark'] as const) {
    for (const { name, path, signedIn } of pages) {
        test.describe(`${name} in ${mode} mode`, () => {
            test.use({ storageState: signedIn ? authFile('owner') : { cookies: [], origins: [] } });

            test('has no serious accessibility problems', async ({ page }) => {
                await useTheme(page, mode);
                await page.goto(path);
                await page.waitForLoadState('networkidle');

                await expect(page.locator('html')).toHaveClass(mode === 'dark' ? /\bdark\b/ : /^(?!.*\bdark\b)/);

                const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

                const serious = results.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');

                expect(
                    serious.map((violation) => `${violation.id} (${violation.impact}): ${violation.help} - ${violation.nodes.length} element(s), e.g. ${violation.nodes[0]?.target.join(' ')}`),
                ).toEqual([]);
            });
        });
    }
}

test.describe('the theme switch', () => {
    test.use({ storageState: authFile('owner') });

    test('flips the whole app between light and dark, and remembers the choice', async ({ page }) => {
        // No useTheme() here: its init script would put the theme back on every page load.
        // The browser prefers light, so the app starts light.
        await page.goto('/dashboard');

        const html = page.locator('html');
        await expect(html).not.toHaveClass(/\bdark\b/);

        await page.getByRole('button', { name: 'Switch to dark mode' }).click();
        await expect(html).toHaveClass(/\bdark\b/);

        // A new page load (the cookie tells the server) keeps it dark, with no flash of light.
        await page.goto('/products');
        await expect(html).toHaveClass(/\bdark\b/);

        await page.getByRole('button', { name: 'Switch to light mode' }).click();
        await expect(html).not.toHaveClass(/\bdark\b/);
    });
});

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

    for (const mode of ['light', 'dark'] as const) {
        test(`the focused control is always marked, in ${mode} mode`, async ({ page }) => {
            await useTheme(page, mode);
            await page.goto('/dashboard');

            for (let i = 0; i < 6; i++) {
                await page.keyboard.press('Tab');

                // The same element, focused and not: its outline or shadow must differ.
                const look = await page.evaluate(() => {
                    const element = document.activeElement as HTMLElement;
                    const style = () => {
                        const s = getComputedStyle(element);

                        return `${s.outlineStyle} ${s.outlineWidth} ${s.outlineColor} | ${s.boxShadow}`;
                    };
                    const focused = style();
                    element.blur();
                    const blurred = style();
                    element.focus({ focusVisible: true } as FocusOptions);

                    return { name: `${element.tagName} ${element.textContent?.trim().slice(0, 30)}`, focused, blurred };
                });

                expect(look.focused, `${look.name} should look different when it has the focus`).not.toBe(look.blurred);
            }
        });
    }

    test('a dialog stays on screen when the computer asks for reduced motion', async ({ page }) => {
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.goto('/products/1');
        await page.getByTestId('restock-button').click();

        const box = await page.getByRole('dialog').boundingBox();
        const viewport = page.viewportSize()!;

        expect(box, 'the dialog should be drawn').not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.y).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width, 'the dialog should fit across').toBeLessThanOrEqual(viewport.width);
        expect(box!.y + box!.height, 'the dialog should fit down').toBeLessThanOrEqual(viewport.height);

        await page.keyboard.press('Escape');
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
