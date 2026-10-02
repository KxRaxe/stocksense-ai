import {
    Boxes,
    ClipboardCheck,
    FileBarChart,
    LayoutGrid,
    Package,
    Receipt,
    ScrollText,
    SlidersHorizontal,
    Tags,
    TrendingUp,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as auditLogIndex } from '@/routes/audit-log';
import { index as categoriesIndex } from '@/routes/categories';
import { index as forecastsIndex } from '@/routes/forecasts';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as productsIndex } from '@/routes/products';
import { index as recommendationsIndex } from '@/routes/recommendations';
import { index as reportsIndex } from '@/routes/reports';
import { index as salesIndex } from '@/routes/sales';
import { edit as systemSettingsEdit } from '@/routes/system-settings';
import { index as usersIndex } from '@/routes/users';
import type { NavItem } from '@/types';

// Items with a `permission` are hidden from users who do not hold it.
export const mainNavItems: NavItem[] = [
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
        title: 'Forecasts',
        href: forecastsIndex(),
        icon: TrendingUp,
        permission: 'forecasts.view',
    },
    {
        title: 'Recommendations',
        href: recommendationsIndex(),
        icon: ClipboardCheck,
        permission: 'recommendations.view',
    },
    {
        title: 'Reports',
        href: reportsIndex(),
        icon: FileBarChart,
        anyPermission: ['reports.view', 'reports.inventory'],
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
    {
        title: 'Audit log',
        href: auditLogIndex(),
        icon: ScrollText,
        permission: 'audit.view',
    },
    {
        title: 'System settings',
        href: systemSettingsEdit(),
        icon: SlidersHorizontal,
        permission: 'settings.manage',
    },
];
