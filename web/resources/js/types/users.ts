import type { Role } from '@/types/auth';

/** A user as listed in the Owner's user management screens. */
export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: Role | null;
    role_label: string | null;
    is_active: boolean;
    is_self: boolean;
    email_verified: boolean;
    two_factor_enabled: boolean;
};

export type RoleOption = {
    value: Role;
    label: string;
};
