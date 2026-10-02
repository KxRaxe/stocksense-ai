import { describe, expect, it } from 'vitest';
import { mainNavItems } from '@/components/main-nav-items';
import { visibleNavItems } from '@/lib/navigation';
import type { NavItem, Permission } from '@/types';

const every: Permission[] = [
    'users.manage',
    'settings.manage',
    'audit.view',
    'catalog.view',
    'catalog.manage',
    'inventory.view',
    'inventory.adjust',
    'sales.view',
    'sales.enter',
    'sales.import',
    'forecasts.view',
    'forecasts.run',
    'recommendations.view',
    'recommendations.decide',
    'reports.view',
    'reports.inventory',
];

const holding =
    (...granted: Permission[]) =>
    (permission: Permission) =>
        granted.includes(permission);

const titles = (items: NavItem[]) => items.map((item) => item.title);

describe('visibleNavItems', () => {
    const items = [
        { title: 'Everyone', href: '/a' },
        { title: 'One', href: '/b', permission: 'catalog.view' as const },
        {
            title: 'Either',
            href: '/c',
            anyPermission: [
                'reports.view',
                'reports.inventory',
            ] as Permission[],
        },
        {
            title: 'Both kinds',
            href: '/d',
            permission: 'catalog.view' as const,
            anyPermission: [
                'reports.view',
                'reports.inventory',
            ] as Permission[],
        },
    ];

    it('shows what needs no permission to everyone', () => {
        expect(titles(visibleNavItems(items, holding()))).toEqual(['Everyone']);
    });

    it('shows an item needing a permission only to those with it', () => {
        expect(titles(visibleNavItems(items, holding('catalog.view')))).toEqual(
            ['Everyone', 'One'],
        );
    });

    it('shows an item needing any of several to those with at least one', () => {
        expect(
            titles(visibleNavItems(items, holding('reports.inventory'))),
        ).toEqual(['Everyone', 'Either']);
        expect(titles(visibleNavItems(items, holding('reports.view')))).toEqual(
            ['Everyone', 'Either'],
        );
    });

    it('wants both when an item names both kinds', () => {
        expect(
            titles(
                visibleNavItems(items, holding('catalog.view', 'reports.view')),
            ),
        ).toEqual(['Everyone', 'One', 'Either', 'Both kinds']);
    });
});

describe('the sidebar', () => {
    const manager: Permission[] = [
        'catalog.view',
        'catalog.manage',
        'inventory.view',
        'inventory.adjust',
        'sales.view',
        'sales.enter',
        'sales.import',
        'forecasts.view',
        'forecasts.run',
        'recommendations.view',
        'recommendations.decide',
        'reports.view',
        'reports.inventory',
    ];

    const staff: Permission[] = [
        'catalog.view',
        'inventory.view',
        'inventory.adjust',
        'sales.view',
        'sales.enter',
        'sales.import',
        'recommendations.view',
        'reports.inventory',
    ];

    it('gives the Owner everything, including the administration', () => {
        expect(
            titles(visibleNavItems(mainNavItems, holding(...every))),
        ).toEqual([
            'Dashboard',
            'Products',
            'Inventory',
            'Sales',
            'Forecasts',
            'Recommendations',
            'Reports',
            'Categories',
            'Users',
            'Audit log',
            'System settings',
        ]);
    });

    it('keeps the administration from a Manager', () => {
        const seen = titles(visibleNavItems(mainNavItems, holding(...manager)));

        expect(seen).toContain('Reports');
        expect(seen).toContain('Forecasts');
        expect(seen).not.toContain('Users');
        expect(seen).not.toContain('Audit log');
        expect(seen).not.toContain('System settings');
    });

    it('shows inventory staff the stock report but not forecasts or administration', () => {
        const seen = titles(visibleNavItems(mainNavItems, holding(...staff)));

        expect(seen).toContain('Reports');
        expect(seen).toContain('Recommendations');
        expect(seen).not.toContain('Forecasts');
        expect(seen).not.toContain('Users');
        expect(seen).not.toContain('Audit log');
        expect(seen).not.toContain('System settings');
    });

    it('shows someone with no permissions only the dashboard', () => {
        expect(titles(visibleNavItems(mainNavItems, holding()))).toEqual([
            'Dashboard',
        ]);
    });
});
