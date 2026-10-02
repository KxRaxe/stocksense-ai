import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import CategoryForm from '@/components/catalog/category-form';
import type { CategoryRow } from '@/types';

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        action,
        method,
    }: {
        children: (state: { processing: boolean; errors: object }) => ReactNode;
        action: string;
        method: string;
    }) => (
        <form action={action} method={method}>
            {children({ processing: false, errors: {} })}
        </form>
    ),
    Link: ({ children }: { children: ReactNode }) => <a href="/">{children}</a>,
}));

const action = { action: '/categories', method: 'post' as const };

const level = () =>
    screen.getByLabelText('Target service level (%)') as HTMLInputElement;

describe('CategoryForm', () => {
    it('starts a new category at 95% unless told otherwise', () => {
        render(<CategoryForm action={action} submitLabel="Create category" />);

        expect(level().value).toBe('95');
    });

    it("starts a new category at the shop's default service level", () => {
        render(
            <CategoryForm
                action={action}
                defaultServiceLevel={90}
                submitLabel="Create category"
            />,
        );

        expect(level().value).toBe('90');
    });

    it('keeps its own level when editing, whatever the default is', () => {
        const category: CategoryRow = {
            id: 4,
            name: 'Hardware',
            description: null,
            service_level: 97.5,
            products_count: 12,
        };

        render(
            <CategoryForm
                action={action}
                category={category}
                defaultServiceLevel={90}
                submitLabel="Save changes"
            />,
        );

        expect(level().value).toBe('97.5');
    });
});
