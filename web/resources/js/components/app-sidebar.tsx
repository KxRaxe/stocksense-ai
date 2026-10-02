import { Link } from '@inertiajs/react';
import { Boxes, LayoutGrid, Package, Receipt, Tags, Users } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { dashboard } from '@/routes';
import { index as categoriesIndex } from '@/routes/categories';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as productsIndex } from '@/routes/products';
import { index as salesIndex } from '@/routes/sales';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

// Items with a `permission` are hidden from users who do not hold it.
const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Products',
        href: productsIndex(),
        icon: Package,
        permission: 'catalog.view',
    },
    {
        title: 'Inventory',
        href: inventoryIndex(),
        icon: Boxes,
        permission: 'inventory.view',
    },
    {
        title: 'Sales',
        href: salesIndex(),
        icon: Receipt,
        permission: 'sales.view',
    },
    {
        title: 'Categories',
        href: categoriesIndex(),
        icon: Tags,
        permission: 'catalog.view',
    },
    {
        title: 'Users',
        href: usersIndex(),
        icon: Users,
        permission: 'users.manage',
    },
];

export function AppSidebar() {
    const can = useCan();

    const visibleItems = mainNavItems.filter(
        (item) => !item.permission || can(item.permission),
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={visibleItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
