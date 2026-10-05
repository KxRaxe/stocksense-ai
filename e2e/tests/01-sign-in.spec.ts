import { expect, test } from '@playwright/test';
import { accounts, password } from '../support/accounts';

// These tests are about being signed out, so they start with no browser state.
test.use({ storageState: { cookies: [], origins: [] } });

test.describe('signing in', () => {
    test('asks for a login before showing anything', async ({ page }) => {
        for (const path of [
            '/dashboard',
            '/products',
            '/forecasts',
            '/recommendations',
            '/reports',
            '/reports/sales',
            '/audit-log',
            '/system-settings',
            '/users',
        ]) {
            await page.goto(path);

            await expect(page, `${path} should lead to the login page`).toHaveURL(/\/login$/);
        }
    });

    test('has no public sign-up', async ({ page }) => {
        const response = await page.goto('/register');

        expect(response?.status()).toBe(404);
    });

    test('refuses a wrong password, says so, and does not say which part was wrong', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(accounts.owner.email);
        await page.getByLabel('Password', { exact: true }).fill('not-the-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByText(/do not match our records/i)).toBeVisible();
    });

    test('says the same for an address that does not exist', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill('nobody-here@stocksense.test');
        await page.getByLabel('Password', { exact: true }).fill('whatever-it-is');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page.getByText(/do not match our records/i)).toBeVisible();
    });

    test('signs in with the right password and signs out again', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(accounts.manager.email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('heading', { name: 'Dashboard' }).first()).toBeVisible();

        await page.getByTestId('sidebar-menu-button').click();
        await page.getByTestId('logout-button').click();

        // Signing out leads back to the front page.
        await expect(page).toHaveURL(/:\d+\/$/);
        await expect(page.getByTestId('landing-sign-in')).toBeVisible();

        // Signed out means signed out: going back does not show the page again.
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login$/);
    });

    test('stops someone guessing: five wrong tries, then asks them to wait', async ({ page }) => {
        // An address of its own, so the real accounts are never locked out.
        const guess = `guess-${Date.now()}@stocksense.test`;

        for (let attempt = 1; attempt <= 5; attempt++) {
            await page.goto('/login');
            await page.getByLabel('Email address').fill(guess);
            await page.getByLabel('Password', { exact: true }).fill(`wrong-${attempt}`);
            await page.getByRole('button', { name: 'Log in' }).click();
            await expect(page.getByText(/do not match our records/i)).toBeVisible();
        }

        await page.goto('/login');
        await page.getByLabel('Email address').fill(guess);
        await page.getByLabel('Password', { exact: true }).fill('one-more');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page.getByText(/too many login attempts/i)).toBeVisible();
    });
});
