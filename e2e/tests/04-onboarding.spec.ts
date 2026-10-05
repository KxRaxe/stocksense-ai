import { expect, test } from '@playwright/test';
import { authFile } from '../support/accounts';
import { clearMail, everythingIn, waitForMail } from '../support/mailpit';

test.use({ storageState: authFile('owner') });

test.describe('a new person joins', () => {
    const stamp = Date.now();
    const name = `Eddie Onboarding ${stamp}`;
    const email = `eddie.${stamp}@stocksense.test`;
    const newPassword = `Correct-horse-${stamp}-battery`;

    let setUpLink = '';

    test('the Owner adds them, and they are emailed a link rather than a password', async ({ page }) => {
        await clearMail();

        await page.goto('/users/create');
        await page.getByLabel('Name').fill(name);
        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Role').selectOption('inventory_staff');
        await page.getByTestId('save-user-button').click();

        await expect(page.getByText('User created. A set-up email has been sent.')).toBeVisible();
        await expect(page.getByTestId(/^user-row-/).filter({ hasText: email })).toBeVisible();

        const mail = await waitForMail('Set up your');

        expect(mail.To.map((to) => to.Address)).toEqual([email]);

        setUpLink = (mail.Text ?? '').match(/https?:\/\/\S*\/reset-password\/\S+/)?.[0]?.replace(/[)>.,]+$/, '') ?? '';

        expect(setUpLink, 'the email should carry a set-up link').not.toBe('');
    });

    test('the email contains nothing personal, and the link carries only a token', async () => {
        const mail = await waitForMail('Set up your');
        const body = [mail.Subject, mail.Text ?? '', mail.HTML ?? ''].join('\n').toLowerCase();

        expect(body).not.toContain(name.toLowerCase());
        expect(body).not.toContain('eddie');
        expect(body).not.toContain(email.toLowerCase());
        expect(body).not.toContain(encodeURIComponent(email).toLowerCase());
        expect(body).not.toContain('demo owner');

        const url = new URL(setUpLink);

        expect(url.search, 'no query string, so no email address in it').toBe('');
        expect(everythingIn(mail)).toContain('/reset-password/');
    });

    test('they choose a password from the link and sign in as inventory staff', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();

        await page.goto(setUpLink);

        // The address is not in the link, so the person types it.
        await page.getByLabel('Email').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(newPassword);
        await page.getByLabel('Confirm password').fill(newPassword);
        await page.getByRole('button', { name: /reset password|set password|save/i }).click();

        await expect(page).toHaveURL(/\/login/);

        await page.getByLabel('Email address').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(newPassword);
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/dashboard$/);
        await expect(page.getByRole('link', { name: 'Recommendations', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Forecasts', exact: true })).toHaveCount(0);
        await expect(page.getByRole('link', { name: 'Users', exact: true })).toHaveCount(0);

        expect((await page.goto('/system-settings'))?.status()).toBe(403);

        await context.close();
    });

    test('the link works once', async ({ browser }) => {
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();

        await page.goto(setUpLink);
        await page.getByLabel('Email').fill(email);
        await page.getByLabel('Password', { exact: true }).fill(`Another-${newPassword}`);
        await page.getByLabel('Confirm password').fill(`Another-${newPassword}`);
        await page.getByRole('button', { name: /reset password|set password|save/i }).click();

        await expect(page.getByText(/invalid|expired/i)).toBeVisible();

        await context.close();
    });

    test('the Owner can deactivate them, and they are shut out at once', async ({ page, browser }) => {
        // They sign in...
        const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
        const theirs = await context.newPage();

        await theirs.goto('/login');
        await theirs.getByLabel('Email address').fill(email);
        await theirs.getByLabel('Password', { exact: true }).fill(newPassword);
        await theirs.getByRole('button', { name: 'Log in' }).click();
        await expect(theirs).toHaveURL(/\/dashboard$/);

        // ...the Owner deactivates them...
        await page.goto('/users');
        await page.getByTestId(/^user-row-/).filter({ hasText: email }).getByRole('link').first().click();
        await page.getByTestId('deactivate-user-button').click();
        await page.getByTestId('confirm-deactivate-button').click();
        await expect(page.getByText('User deactivated.')).toBeVisible();

        // ...and the next thing they do takes them to the login page.
        await theirs.goto('/dashboard');
        await expect(theirs).toHaveURL(/\/login/);

        await context.close();
    });
});
