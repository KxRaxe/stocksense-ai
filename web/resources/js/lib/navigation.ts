import type { NavItem, Permission } from '@/types';

/**
 * The items a person may see. An item with a `permission` needs that one; one
 * with `anyPermission` needs at least one of those; one with neither is for
 * everyone. This only decides what is shown: the server checks every request.
 */
export function visibleNavItems(
    items: NavItem[],
    can: (permission: Permission) => boolean,
): NavItem[] {
    return items.filter(
        (item) =>
            (!item.permission || can(item.permission)) &&
            (!item.anyPermission || item.anyPermission.some(can)),
    );
}
