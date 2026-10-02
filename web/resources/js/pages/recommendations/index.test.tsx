import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RecommendationsIndex from '@/pages/recommendations/index';
import { makeRecommendation, noFilters } from '@/test/replenishment';
import type { RecommendationsProps } from '@/types';

const inertia = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Form: ({ children }: { children: (state: object) => ReactNode }) => (
        <form>{children({ processing: false, errors: {} })}</form>
    ),
    router: { post: inertia.post, get: inertia.get },
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string | { url: string };
        children: ReactNode;
    }) => (
        <a href={typeof href === 'string' ? href : href.url} {...rest}>
            {children}
        </a>
    ),
}));

const forecast = {
    id: 3,
    granularity: 'week' as const,
    finished_at: '2026-10-01T08:00:00+08:00',
    age_days: 4,
    stale: false,
};

function props(
    changes: Partial<RecommendationsProps> = {},
): RecommendationsProps {
    const rows = [
        makeRecommendation({ id: 1 }),
        makeRecommendation({
            id: 2,
            risk: 'low',
            risk_label: 'Low',
            product: {
                ...makeRecommendation().product,
                id: 14,
                name: 'Cement',
                sku: 'HW-2',
            },
        }),
    ];

    return {
        recommendations: {
            data: rows,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 2,
            total: 2,
            prev_page_url: null,
            next_page_url: null,
        },
        counts: { critical: 1, low: 1, watch: 0, overstock: 0, ordered: 0 },
        filters: noFilters,
        categories: [{ id: 1, name: 'Hardware' }],
        forecast,
        can: { decide: true },
        ...changes,
    };
}

const empty = {
    data: [],
    current_page: 1,
    last_page: 1,
    from: null,
    to: null,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
};

describe('Recommendations page', () => {
    afterEach(() => vi.clearAllMocks());

    it('says there is nothing to go on before the first forecast, and where to get one', () => {
        render(
            <RecommendationsIndex
                {...props({ forecast: null, recommendations: empty })}
            />,
        );

        expect(screen.getByTestId('no-forecast').textContent).toContain(
            'there is not one yet',
        );
        expect(
            screen
                .getByRole('link', { name: 'Go to Forecasts' })
                .getAttribute('href'),
        ).toBe('/forecasts');
        expect(screen.queryByTestId('recommendation-list')).toBeNull();
        expect(screen.queryByTestId('refresh-button')).toBeNull();
    });

    it('lists the recommendations, each with its own card', () => {
        render(<RecommendationsIndex {...props()} />);

        expect(screen.getByTestId('recommendation-1')).toBeTruthy();
        expect(screen.getByTestId('recommendation-2')).toBeTruthy();
        expect(screen.getByText('Cement')).toBeTruthy();
    });

    it('says which forecast the advice is based on', () => {
        render(<RecommendationsIndex {...props()} />);

        expect(screen.getByTestId('forecast-basis').textContent).toBe(
            'Based on the weekly forecast made 4 days ago.',
        );
    });

    it('words the age of a forecast made today, or yesterday', () => {
        const { unmount } = render(
            <RecommendationsIndex
                {...props({ forecast: { ...forecast, age_days: 0 } })}
            />,
        );

        expect(screen.getByTestId('forecast-basis').textContent).toContain(
            'made today.',
        );

        unmount();
        render(
            <RecommendationsIndex
                {...props({
                    forecast: {
                        ...forecast,
                        age_days: 1,
                        granularity: 'month',
                    },
                })}
            />,
        );

        expect(screen.getByTestId('forecast-basis').textContent).toBe(
            'Based on the monthly forecast made 1 day ago.',
        );
    });

    it('warns when the forecast is getting old', () => {
        render(
            <RecommendationsIndex
                {...props({
                    forecast: { ...forecast, age_days: 20, stale: true },
                })}
            />,
        );

        const basis = screen.getByTestId('forecast-basis').textContent;

        expect(basis).toContain('made 20 days ago');
        expect(basis).toContain('getting old');
    });

    it('does not warn about a recent forecast', () => {
        render(<RecommendationsIndex {...props()} />);

        expect(screen.getByTestId('forecast-basis').textContent).not.toContain(
            'getting old',
        );
    });

    it.each([
        ['todo', 'Nothing needs ordering right now.'],
        [
            'overstock',
            'No product has more stock than it is likely to sell soon.',
        ],
        ['decided', 'Nothing has been decided yet.'],
    ] as const)('says so when the %s list is empty', (view, message) => {
        render(
            <RecommendationsIndex
                {...props({
                    recommendations: empty,
                    filters: { ...noFilters, view },
                })}
            />,
        );

        expect(screen.getByTestId('no-recommendations').textContent).toBe(
            message,
        );
    });

    it('says no products match when filters leave nothing', () => {
        render(
            <RecommendationsIndex
                {...props({
                    recommendations: empty,
                    filters: { ...noFilters, search: 'zzz' },
                })}
            />,
        );

        expect(screen.getByTestId('no-recommendations').textContent).toBe(
            'No products match.',
        );
    });

    it('lets a decider recalculate now', async () => {
        render(<RecommendationsIndex {...props()} />);

        await userEvent.setup().click(screen.getByTestId('refresh-button'));

        expect(inertia.post).toHaveBeenCalledWith(
            '/recommendations/refresh',
            {},
            { preserveScroll: true },
        );
    });

    it('is look-only for someone who cannot decide', () => {
        render(<RecommendationsIndex {...props({ can: { decide: false } })} />);

        expect(screen.queryByTestId('refresh-button')).toBeNull();
        expect(screen.queryByTestId('accept-button')).toBeNull();
        expect(screen.getByTestId('recommendation-1')).toBeTruthy();
    });

    it('shows the counts and how to read the numbers', () => {
        render(<RecommendationsIndex {...props()} />);

        expect(screen.getByTestId('count-critical').textContent).toContain('1');
        expect(
            screen.getByText(
                /Reorder point is the stock level at which to order/,
            ),
        ).toBeTruthy();
    });
});
