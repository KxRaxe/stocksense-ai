import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ReportFilters from '@/components/reports/report-filters';
import type { ReportFilterName, ReportFiltersState } from '@/types';

const inertia = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { get: inertia.get } }));

const filters: ReportFiltersState = {
    from: '2026-09-06',
    to: '2026-10-05',
    category: null,
    granularity: 'week',
};

const categories = [
    { id: 1, name: 'Food' },
    { id: 2, name: 'Hardware' },
];

function show(
    supports: ReportFilterName[],
    state: ReportFiltersState = filters,
    errors: Record<string, string> = {},
) {
    return render(
        <ReportFilters
            reportKey="sales"
            supports={supports}
            filters={state}
            categories={categories}
            errors={errors}
        />,
    );
}

describe('ReportFilters', () => {
    afterEach(() => vi.clearAllMocks());

    it('offers only the filters the report understands', () => {
        show(['category']);

        expect(screen.queryByTestId('filter-from')).toBeNull();
        expect(screen.queryByTestId('filter-to')).toBeNull();
        expect(screen.queryByTestId('filter-granularity')).toBeNull();
        expect(screen.getByTestId('filter-category')).toBeTruthy();
    });

    it('offers a period, a category and weekly or monthly when it understands them all', () => {
        show(['date', 'category', 'granularity']);

        expect(
            (screen.getByTestId('filter-from') as HTMLInputElement).value,
        ).toBe('2026-09-06');
        expect(
            (screen.getByTestId('filter-to') as HTMLInputElement).value,
        ).toBe('2026-10-05');
        expect(
            (screen.getByTestId('filter-granularity') as HTMLSelectElement)
                .value,
        ).toBe('week');
        expect(
            Array.from(
                (screen.getByTestId('filter-category') as HTMLSelectElement)
                    .options,
            ).map((option) => option.text),
        ).toEqual(['All categories', 'Food', 'Hardware']);
    });

    it('starts from the category that was chosen', () => {
        show(['date', 'category'], { ...filters, category: 2 });

        expect(
            (screen.getByTestId('filter-category') as HTMLSelectElement).value,
        ).toBe('2');
    });

    it('stops the period from running backwards', () => {
        show(['date']);

        expect(screen.getByTestId('filter-from').getAttribute('max')).toBe(
            '2026-10-05',
        );
        expect(screen.getByTestId('filter-to').getAttribute('min')).toBe(
            '2026-09-06',
        );
    });

    it('asks for the report with what was chosen, and nothing else', async () => {
        const user = userEvent.setup();

        show(['date', 'category']);

        await user.selectOptions(screen.getByTestId('filter-category'), '2');
        await user.click(screen.getByTestId('apply-filters'));

        expect(inertia.get).toHaveBeenCalledWith(
            '/reports/sales',
            { from: '2026-09-06', to: '2026-10-05', category: '2' },
            { preserveScroll: true },
        );
    });

    it('leaves the category out when all are wanted', async () => {
        show(['date', 'category']);

        await userEvent.setup().click(screen.getByTestId('apply-filters'));

        expect(inertia.get.mock.calls[0][1]).toEqual({
            from: '2026-09-06',
            to: '2026-10-05',
        });
    });

    it('sends a changed date', async () => {
        const user = userEvent.setup();

        show(['date']);

        const from = screen.getByTestId('filter-from');

        await user.clear(from);
        await user.type(from, '2026-08-01');
        await user.click(screen.getByTestId('apply-filters'));

        expect(inertia.get.mock.calls[0][1]).toEqual({
            from: '2026-08-01',
            to: '2026-10-05',
        });
    });

    it('sends monthly when chosen, and no dates for a report without them', async () => {
        const user = userEvent.setup();

        show(['granularity']);

        await user.selectOptions(
            screen.getByTestId('filter-granularity'),
            'month',
        );
        await user.click(screen.getByTestId('apply-filters'));

        expect(inertia.get.mock.calls[0][1]).toEqual({ granularity: 'month' });
    });

    it('shows what was wrong with a filter', () => {
        show(['date', 'category'], filters, {
            from: 'The start date must be a date such as 2026-09-01.',
            category: 'That category does not exist.',
        });

        expect(screen.getByText(/The start date must be a date/)).toBeTruthy();
        expect(screen.getByText('That category does not exist.')).toBeTruthy();
    });
});
