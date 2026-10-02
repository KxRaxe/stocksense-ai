import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ProductForm from '@/components/catalog/product-form';
import type { Option, ProductDetails } from '@/types';

// Errors the mocked Form hands to the form, like a failed server validation.
const formState = vi.hoisted(() => ({ errors: {} as Record<string, string> }));

// Stand-ins for Inertia's Form and Link, which need a running Inertia app.
vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        action,
        method,
        className,
    }: {
        children: (state: {
            processing: boolean;
            errors: Record<string, string>;
        }) => ReactNode;
        action: string;
        method: string;
        className?: string;
    }) => (
        <form action={action} method={method} className={className}>
            {children({ processing: false, errors: formState.errors })}
        </form>
    ),
    // Like the real Link, accept a Wayfinder route object as well as a string.
    Link: ({
        href,
        children,
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
}));

const categories: Option[] = [
    { id: 1, name: 'Food and beverages' },
    { id: 5, name: 'Hardware and construction' },
];

const product: ProductDetails = {
    id: 13,
    sku: 'HW-001',
    name: 'Common wire nails',
    category: 'Hardware and construction',
    category_id: 5,
    unit: 'kg',
    unit_cost: 62,
    unit_price: 85.5,
    is_active: true,
    lead_time_days: 14,
    moq: 5,
    pack_size: 5,
    reorder_point_override: 20,
    safety_stock_override: null,
};

const action = { action: '/products', method: 'post' as const };

const field = (label: string) =>
    screen.getByLabelText(label) as HTMLInputElement;

describe('ProductForm', () => {
    afterEach(() => {
        formState.errors = {};
    });

    it('starts a new product with sensible defaults', () => {
        render(
            <ProductForm
                action={action}
                categories={categories}
                withOpeningStock
                submitLabel="Create product"
            />,
        );

        expect(field('SKU').value).toBe('');
        expect(field('Unit').value).toBe('pc');
        expect(field('Lead time (days)').value).toBe('7');
        expect(field('Minimum order quantity').value).toBe('1');
        expect(field('Pack size').value).toBe('1');
        expect(field('Reorder point').value).toBe('');
        expect(field('Opening stock').value).toBe('0');
        expect(
            screen.getByRole('button', { name: 'Create product' }),
        ).toBeTruthy();
    });

    it('fills in an existing product, without opening stock', () => {
        render(
            <ProductForm
                action={action}
                categories={categories}
                product={product}
                submitLabel="Save changes"
            />,
        );

        expect(field('SKU').value).toBe('HW-001');
        expect(field('Name').value).toBe('Common wire nails');
        expect(
            (screen.getByLabelText('Category') as HTMLSelectElement).value,
        ).toBe('5');
        expect(field('Unit cost (₱)').value).toBe('62');
        expect(field('Unit price (₱)').value).toBe('85.5');
        expect(field('Reorder point').value).toBe('20');
        expect(field('Safety stock').value).toBe('');

        // Stock changes go through restocks and counts, not the edit form.
        expect(screen.queryByLabelText('Opening stock')).toBeNull();
    });

    it('offers each category, with a disabled prompt first', () => {
        render(
            <ProductForm
                action={action}
                categories={categories}
                submitLabel="Create"
            />,
        );

        const options = [
            ...(screen.getByLabelText('Category') as HTMLSelectElement).options,
        ];

        expect(options.map((option) => option.text)).toEqual([
            'Choose a category',
            'Food and beverages',
            'Hardware and construction',
        ]);
        expect(options[0].disabled).toBe(true);
    });

    it('points to category creation when there are no categories', () => {
        render(
            <ProductForm
                action={action}
                categories={[]}
                submitLabel="Create"
            />,
        );

        expect(
            screen
                .getByRole('link', { name: 'Create one first.' })
                .getAttribute('href'),
        ).toBe('/categories/create');
    });

    it('shows the server error under the field it belongs to', () => {
        formState.errors = {
            sku: 'The sku has already been taken.',
            unit_price: 'The unit price must be at least 0.',
        };

        render(
            <ProductForm
                action={action}
                categories={categories}
                submitLabel="Create"
            />,
        );

        expect(
            screen.getByText('The sku has already been taken.'),
        ).toBeTruthy();
        expect(
            screen.getByText('The unit price must be at least 0.'),
        ).toBeTruthy();
    });

    it('posts to the given action', () => {
        const { container } = render(
            <ProductForm
                action={action}
                categories={categories}
                submitLabel="Create"
            />,
        );

        const form = container.querySelector('form');

        expect(form?.getAttribute('action')).toBe('/products');
        expect(form?.getAttribute('method')).toBe('post');
    });
});
