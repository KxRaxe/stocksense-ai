import { render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Pagination from '@/components/pagination';
import type { Paginated } from '@/types';

// A plain link stands in for Inertia's, which needs a running Inertia app.
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: ComponentProps<'a'>) => (
        <a href={href}>{children}</a>
    ),
}));

function page(overrides: Partial<Paginated<unknown>> = {}): Paginated<unknown> {
    return {
        data: [],
        current_page: 1,
        last_page: 3,
        from: 1,
        to: 15,
        total: 42,
        prev_page_url: null,
        next_page_url: '/products?page=2',
        ...overrides,
    };
}

describe('Pagination', () => {
    it('says which rows are showing', () => {
        render(<Pagination paginator={page()} />);

        expect(screen.getByText(/Showing 1-15 of 42/)).toBeTruthy();
    });

    it('links to the next page and disables Previous on the first page', () => {
        render(<Pagination paginator={page()} />);

        expect(
            screen.getByRole('link', { name: 'Next' }).getAttribute('href'),
        ).toBe('/products?page=2');
        expect(
            (
                screen.getByRole('button', {
                    name: 'Previous',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(screen.queryByRole('link', { name: 'Previous' })).toBeNull();
    });

    it('links both ways in the middle and disables Next on the last page', () => {
        const { unmount } = render(
            <Pagination
                paginator={page({
                    current_page: 2,
                    from: 16,
                    to: 30,
                    prev_page_url: '/products?page=1',
                    next_page_url: '/products?page=3',
                })}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'Previous' }).getAttribute('href'),
        ).toBe('/products?page=1');
        expect(
            screen.getByRole('link', { name: 'Next' }).getAttribute('href'),
        ).toBe('/products?page=3');
        unmount();

        render(
            <Pagination
                paginator={page({
                    current_page: 3,
                    from: 31,
                    to: 42,
                    prev_page_url: '/products?page=2',
                    next_page_url: null,
                })}
            />,
        );

        expect(
            (screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement)
                .disabled,
        ).toBe(true);
    });

    it('shows no buttons when everything fits on one page', () => {
        render(
            <Pagination
                paginator={page({
                    last_page: 1,
                    total: 5,
                    to: 5,
                    next_page_url: null,
                })}
            />,
        );

        expect(screen.getByText(/Showing 1-5 of 5/)).toBeTruthy();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('renders nothing when there are no results', () => {
        const { container } = render(
            <Pagination
                paginator={page({
                    total: 0,
                    from: null,
                    to: null,
                    last_page: 1,
                    next_page_url: null,
                })}
            />,
        );

        expect(container.textContent).toBe('');
    });
});
