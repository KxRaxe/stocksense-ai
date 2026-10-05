import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import LinkTabs from '@/components/link-tabs';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const tabs = [
    { key: 'todo', label: 'To decide', href: '/r?view=todo' },
    { key: 'decided', label: 'Decided', href: '/r?view=decided' },
];

describe('LinkTabs', () => {
    it('is a labelled tab list of links', () => {
        render(
            <LinkTabs
                label="Show"
                tabs={tabs}
                current="todo"
                testPrefix="view"
            />,
        );

        expect(screen.getByRole('tablist', { name: 'Show' })).toBeTruthy();
        expect(screen.getByTestId('view-todo').getAttribute('href')).toBe(
            '/r?view=todo',
        );
        expect(screen.getByTestId('view-decided').textContent).toBe('Decided');
    });

    it('marks only the current tab as selected', () => {
        render(
            <LinkTabs
                label="Show"
                tabs={tabs}
                current="decided"
                testPrefix="view"
            />,
        );

        expect(
            screen.getByTestId('view-decided').getAttribute('aria-selected'),
        ).toBe('true');
        expect(
            screen.getByTestId('view-todo').getAttribute('aria-selected'),
        ).toBe('false');
    });
});
