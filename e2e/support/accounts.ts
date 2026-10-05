/**
 * The demo accounts the end-to-end stack is seeded with (DemoUsersSeeder). They exist only
 * on the throwaway test stack; the production stack refuses to create them.
 */
export const password = 'password';

export const accounts = {
    owner: { email: 'owner@stocksense.test', name: 'Demo Owner' },
    manager: { email: 'manager@stocksense.test', name: 'Demo Manager' },
    staff: { email: 'staff@stocksense.test', name: 'Demo Inventory Staff' },
} as const;

export type Role = keyof typeof accounts;

/** Where each role's signed-in browser state is kept (written by global-setup.ts). */
export const authFile = (role: Role) => `.auth/${role}.json`;
