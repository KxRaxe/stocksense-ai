import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ListFilters from '@/components/catalog/list-filters';

// The mock is created up front so the tests can look at how it was called.
const get = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ router: { get } }));

const categories = [
    { id: 1, name: 'Food and beverages' },
    { id: 5, name: 'Hardware and construction' },
];

const extra = {
    name: 'status',
    label: 'Status',
    choices: [
        { value: 'active', label: 'Active' },
        { value: 'archived', label: 'Archived' },
    ],
};

function setup(
    filters: Record<string, string | number | null> = {
        search: '',
        category: null,
        status: 'active',
    },
) {
    // Fake timers let the test skip the 300 ms typing pause.
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });

    render(
        <ListFilters
            url="/products"
            filters={filters}
            categories={categories}
            extra={extra}
        />,
    );

    return user;
}

describe('ListFilters', () => {
    beforeEach(() => {
        // shouldAdvanceTime keeps the clock moving so user-event's own short
        // pauses resolve; the 300 ms search pause is still stepped by hand.
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('searches once, after typing pauses, and keeps the other filters', async () => {
        const user = setup();

        await user.type(screen.getByLabelText('Search'), 'nails');

        // Still typing: nothing sent yet.
        expect(get).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(300);

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/products',
            { search: 'nails', status: 'active' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    });

    it('applies a category straight away and leaves empty values out of the URL', async () => {
        const user = setup();

        await user.selectOptions(screen.getByLabelText('Category'), '5');

        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith(
            '/products',
            { category: '5', status: 'active' },
            expect.any(Object),
        );
    });

    it('drops the category again when "All categories" is chosen', async () => {
        const user = setup({ search: '', category: 5, status: 'active' });

        await user.selectOptions(screen.getByLabelText('Category'), '');

        expect(get).toHaveBeenCalledWith(
            '/products',
            { status: 'active' },
            expect.any(Object),
        );
    });

    it('applies the extra dropdown under its own name', async () => {
        const user = setup();

        await user.selectOptions(screen.getByLabelText('Status'), 'archived');

        expect(get).toHaveBeenCalledWith(
            '/products',
            { status: 'archived' },
            expect.any(Object),
        );
    });

    it('starts from the filters it was given', () => {
        setup({ search: 'cement', category: 1, status: 'archived' });

        expect(
            (screen.getByLabelText('Search') as HTMLInputElement).value,
        ).toBe('cement');
        expect(
            (screen.getByLabelText('Category') as HTMLSelectElement).value,
        ).toBe('1');
        expect(
            (screen.getByLabelText('Status') as HTMLSelectElement).value,
        ).toBe('archived');
    });

    it('does not reload just because it was shown', async () => {
        setup({ search: 'cement', category: null, status: 'active' });

        await vi.advanceTimersByTimeAsync(1000);

        expect(get).not.toHaveBeenCalled();
    });
});
