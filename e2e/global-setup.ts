import { chromium, type FullConfig } from '@playwright/test';
import { accounts, authFile, password, type Role } from './support/accounts';

/**
 * Signs in once as each role and keeps the browser state, so the tests do not each sign in
 * (signing in is rate limited, and it is tested on its own in tests/01-sign-in.spec.ts).
 */
export default async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL!;
    const browser = await chromium.launch();

    for (const role of Object.keys(accounts) as Role[]) {
        const context = await browser.newContext({ baseURL });
        const page = await context.newPage();

        await page.goto('/login');
        await page.getByLabel('Email address').fill(accounts[role].email);
        await page.getByLabel('Password', { exact: true }).fill(password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL('**/dashboard', { timeout: 30_000 });

        await context.storageState({ path: authFile(role) });
        await context.close();
    }

    await browser.close();
}
