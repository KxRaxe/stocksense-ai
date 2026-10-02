import { usePage } from '@inertiajs/react';
import type { Permission } from '@/types';

/**
 * Returns a function that tells whether the signed-in user holds a permission.
 * This only controls what the interface shows; the server checks permissions
 * on every request.
 */
export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage().props;

    return (permission) => auth.permissions.includes(permission);
}
