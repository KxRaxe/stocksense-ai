import { expect, test } from '@playwright/test';
import { authFile, type Role } from '../support/accounts';

/** What each role may open, and what the server must refuse (as the access matrix says). */
const matrix: Record<Role, { sees: string[]; hidden: string[]; allowed: string[]; forbidden: string[] }> = {
    owner: {
        sees: ['Dashboard', 'Products', 'Inventory', 'Sales', 'Forecasts', 'Recommendations', 'Reports', 'Categories', 'Users', 'Audit log', 'System settings'],
        hidden: [],
        allowed: ['/dashboard', '/users', '/audit-log', '/system-settings', '/reports/sales', '/reports/inventory', '/reports/forecast-accuracy', '/reports/replenishment', '/forecasts', '/recommendations'],
        forbidden: [],
    },
    manager: {
        sees: ['Dashboard', 'Products', 'Inventory', 'Sales', 'Forecasts', 'Recommendations', 'Reports', 'Categories'],
        hidden: ['Users', 'Audit log', 'System settings'],
        allowed: ['/dashboard', '/products', '/forecasts', '/recommendations', '/reports/sales', '/reports/inventory', '/reports/replenishment'],
        forbidden: ['/users', '/audit-log', '/system-settings'],
    },
    staff: {
        sees: ['Dashboard', 'Products', 'Inventory', 'Sales', 'Recommendations', 'Reports', 'Categories'],
        hidden: ['Forecasts', 'Users', 'Audit log', 'System settings'],
        allowed: ['/dashboard', '/products', '/inventory', '/sales', '/sales/create', '/recommendations', '/reports/inventory'],
        forbidden: ['/forecasts', '/forecasts/accuracy', '/users', '/audit-log', '/system-settings', '/reports/sales', '/reports/forecast-accuracy', '/reports/replenishment', '/products/create', '/categories/create'],
    },
};

for (const role of Object.keys(matrix) as Role[]) {
    test.describe(`as ${role}`, () => {
        test.use({ storageState: authFile(role) });

        test('sees the parts of the app that are theirs, and only those', async ({ page }) => {
            await page.goto('/dashboard');

            for (const item of matrix[role].sees) {
                await expect(page.getByRole('link', { name: item, exact: true }).first(), `${role} should see ${item}`).toBeVisible();
            }

            for (const item of matrix[role].hidden) {
                await expect(page.getByRole('link', { name: item, exact: true }), `${role} should not see ${item}`).toHaveCount(0);
            }
        });

        test('can open what is theirs', async ({ page }) => {
            for (const path of matrix[role].allowed) {
                const response = await page.goto(path);

                expect(response?.status(), `${role} opening ${path}`).toBe(200);
            }
        });

        test('is refused the rest by the server, not just hidden', async ({ page }) => {
            for (const path of matrix[role].forbidden) {
                const response = await page.goto(path);

                expect(response?.status(), `${role} opening ${path}`).toBe(403);
            }
        });
    });
}

test.describe('what each role can do', () => {
    test.describe('inventory staff', () => {
        test.use({ storageState: authFile('staff') });

        test('can look at recommendations but not decide', async ({ page }) => {
            await page.goto('/recommendations');

            await expect(page.getByRole('heading', { name: 'Recommendations' }).first()).toBeVisible();
            await expect(page.getByTestId('accept-button')).toHaveCount(0);
            await expect(page.getByTestId('refresh-button')).toHaveCount(0);
        });

        test('cannot start a forecast, even by asking the server directly', async ({ page }) => {
            await page.goto('/dashboard');

            const token = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value);
            const response = await page.request.post('/forecasts/run', {
                headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
                data: { granularity: 'week' },
            });

            expect(response.status()).toBe(403);
        });

        test('sees the dashboard without the forecast', async ({ page }) => {
            await page.goto('/dashboard');

            await expect(page.getByTestId('kpi-sales')).toBeVisible();
            await expect(page.getByTestId('kpi-accuracy')).toHaveCount(0);
            await expect(page.getByTestId('panel-forecast')).toHaveCount(0);
        });

        test('can open the stock report but is not offered the others', async ({ page }) => {
            await page.goto('/reports');

            await expect(page.getByTestId('report-inventory')).toBeVisible();
            await expect(page.getByTestId('report-sales')).toHaveCount(0);
        });
    });

    test.describe('the manager', () => {
        test.use({ storageState: authFile('manager') });

        test('can decide on recommendations and run forecasts, but not administer', async ({ page }) => {
            await page.goto('/forecasts');
            await expect(page.getByTestId('run-forecast-button')).toBeVisible();

            await page.goto('/recommendations');
            await expect(page.getByTestId('refresh-button').or(page.getByTestId('no-forecast'))).toBeVisible();
        });
    });
});
