export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

/**
 * Mirrors App\Enums\Role. A PHP test (RbacFrontendParityTest) fails when this
 * list and the enum drift apart.
 */
export type Role = 'owner' | 'manager' | 'inventory_staff';

/**
 * Mirrors App\Enums\Permission. Used only to show or hide interface elements;
 * the server enforces every permission itself.
 */
export type Permission =
    | 'users.manage'
    | 'settings.manage'
    | 'audit.view'
    | 'catalog.view'
    | 'catalog.manage'
    | 'inventory.view'
    | 'inventory.adjust'
    | 'sales.view'
    | 'sales.enter'
    | 'sales.import'
    | 'forecasts.view'
    | 'forecasts.run'
    | 'recommendations.view'
    | 'recommendations.decide'
    | 'reports.view'
    | 'reports.inventory';

export type Auth = {
    user: User;
    role: Role | null;
    permissions: Permission[];
};
