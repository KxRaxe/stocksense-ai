import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import Dashboard from '@/pages/dashboard';
import { emptyDashboard, makeDashboard } from '@/test/reporting';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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

const text = (testId: string) => screen.getByTestId(testId).textContent;
const kpi = (testId: string) =>
    within(screen.getByTestId(testId)).getByTestId('kpi-value').textContent;

describe('Dashboard', () => {
    it('says what period the sales are for', () => {
        render(<Dashboard {...makeDashboard()} />);

        expect(
            screen.getByText(
                'How the shop is doing. Sales are for the last 30 days, Sep 6, 2026 to Oct 5, 2026.',
            ),
        ).toBeTruthy();
    });

    describe('the headline figures', () => {
        it('shows sales with how they compare', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(kpi('kpi-sales')).toBe('₱2,300.00');
            expect(text('sales-change')).toBe('+130.0% on the 30 days before');
            expect(text('kpi-sales')).toContain('130 units sold');
        });

        it('says so when the 30 days before had no sales to compare with', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        sales: {
                            revenue: 100,
                            units: 4,
                            previous_revenue: 0,
                            previous_units: 0,
                            revenue_change: null,
                            units_change: null,
                        },
                    })}
                />,
            );

            expect(text('sales-change')).toBe('No sales in the 30 days before');
        });

        it('shows a fall as a fall', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        sales: {
                            revenue: 500,
                            units: 5,
                            previous_revenue: 1000,
                            previous_units: 10,
                            revenue_change: -50,
                            units_change: -50,
                        },
                    })}
                />,
            );

            expect(text('sales-change')).toBe('-50.0% on the 30 days before');
        });

        it('shows the stock value at cost, and what is in and out of stock', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(kpi('kpi-stock')).toBe('₱7,500.00');
            expect(text('kpi-stock')).toContain('4 of 5 products in stock');
            expect(text('out-of-stock')).toBe('1 out of stock');
        });

        it('does not mention out of stock when nothing is', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        stock: {
                            cost_value: 100,
                            retail_value: 150,
                            products: 3,
                            in_stock: 3,
                            out_of_stock: 0,
                        },
                    })}
                />,
            );

            expect(screen.queryByTestId('out-of-stock')).toBeNull();
        });

        it('links the stock to the inventory and the risk to the recommendations', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(screen.getByTestId('kpi-stock').getAttribute('href')).toBe(
                '/inventory',
            );
            expect(screen.getByTestId('kpi-risk').getAttribute('href')).toBe(
                '/recommendations',
            );
        });

        it('counts what needs ordering, with the detail', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(kpi('kpi-risk')).toBe('1');
            expect(text('kpi-risk')).toContain('1 critical, 0 low');
            expect(text('kpi-risk')).toContain('2 to watch');
        });

        it.each([
            [{ critical: 2, low: 1, watch: 0, needs_attention: 3 }, 'text-red'],
            [
                { critical: 0, low: 4, watch: 0, needs_attention: 4 },
                'text-amber',
            ],
            [
                { critical: 0, low: 0, watch: 3, needs_attention: 0 },
                'text-emerald',
            ],
        ])(
            'colours the count to match how urgent it is (%o)',
            (risk, colour) => {
                render(<Dashboard {...makeDashboard({ risk })} />);

                expect(
                    within(screen.getByTestId('kpi-risk')).getByTestId(
                        'kpi-value',
                    ).className,
                ).toContain(colour);
            },
        );

        it('shows how accurate the forecast has been, against last year', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(kpi('kpi-accuracy')).toBe('16.0%');
            expect(text('kpi-accuracy')).toContain('Typical forecast error');
            expect(text('kpi-accuracy')).toContain(
                'Same week last year: 20.0%',
            );
            expect(
                screen.getByTestId('kpi-accuracy').getAttribute('href'),
            ).toBe('/forecasts/accuracy');
        });

        it('says there is no forecast yet, and where to make one', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        forecast: {
                            has_run: false,
                            granularity: 'week',
                            finished_at: null,
                            age_days: null,
                            stale: false,
                            accuracy: null,
                            history: [],
                            forecast: [],
                        },
                    })}
                />,
            );

            expect(kpi('kpi-accuracy')).toBe('No forecast yet');
            expect(
                screen.getByTestId('kpi-accuracy').getAttribute('href'),
            ).toBe('/forecasts');
            expect(text('no-forecast')).toContain(
                'There is no weekly forecast yet.',
            );
        });

        it('says when accuracy could not be measured', () => {
            const base = makeDashboard();

            render(
                <Dashboard
                    {...makeDashboard({
                        forecast: { ...base.forecast!, accuracy: null },
                    })}
                />,
            );

            expect(kpi('kpi-accuracy')).toBe('Not measured');
        });
    });

    describe('the panels', () => {
        it('shows the sales by week, the categories, the forecast, the alerts and the movers', () => {
            render(<Dashboard {...makeDashboard()} />);

            for (const panel of [
                'panel-sales-trend',
                'panel-categories',
                'panel-forecast',
                'panel-alerts',
                'panel-top-movers',
                'panel-slow-movers',
            ]) {
                expect(screen.getByTestId(panel)).toBeTruthy();
            }

            expect(screen.getByTestId('sales-trend-chart')).toBeTruthy();
            expect(screen.getByTestId('forecast-chart')).toBeTruthy();
        });

        it('shares the revenue between the categories', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(text('category-1')).toContain('Food');
            expect(text('category-1')).toContain('₱1,500.00 · 65.2%');
            expect(text('category-2')).toContain('34.8%');
        });

        it('lists the most urgent products with what to order', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(text('alert-9')).toContain('Soap');
            expect(text('alert-9')).toContain(
                '10 pc available against 70 expected to sell',
            );
            expect(text('alert-9')).toContain('Order 130.');
            expect(text('alert-9')).toContain('Critical');
        });

        it('offers all the recommendations when there are more than are listed', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        risk: {
                            critical: 6,
                            low: 2,
                            watch: 0,
                            needs_attention: 8,
                        },
                    })}
                />,
            );

            expect(text('see-recommendations')).toBe(
                'See all 8 recommendations',
            );
        });

        it('says when nothing needs ordering', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        alerts: [],
                        risk: {
                            critical: 0,
                            low: 0,
                            watch: 0,
                            needs_attention: 0,
                        },
                    })}
                />,
            );

            expect(text('no-alerts')).toBe('Nothing needs ordering right now.');
        });

        it('lists the best and the slowest sellers', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(text('top-movers')).toContain('Nails');
            expect(text('top-movers')).toContain('₱800.00');
            expect(text('slow-movers')).toContain('Paint');
            expect(text('slow-movers')).toContain('10 pc on hand');
            expect(text('slow-movers')).toContain('₱1,000.00');
        });

        it('says so when nothing has sold', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        top_movers: [],
                        categories: [],
                        slow_movers: [],
                    })}
                />,
            );

            expect(text('no-top-movers')).toBe(
                'Nothing has sold in this period.',
            );
            expect(text('no-categories')).toBe(
                'Nothing has sold in this period.',
            );
            expect(text('no-slow-movers')).toBe('No product is in stock.');
        });

        it('warns when the forecast is old', () => {
            const base = makeDashboard();

            render(
                <Dashboard
                    {...makeDashboard({
                        forecast: {
                            ...base.forecast!,
                            stale: true,
                            age_days: 20,
                        },
                    })}
                />,
            );

            expect(text('stale-forecast')).toContain(
                'The forecast is 20 days old',
            );
        });

        it('does not warn about a recent one', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(screen.queryByTestId('stale-forecast')).toBeNull();
        });
    });

    describe('what a person is not allowed to see', () => {
        it('leaves out the forecast for someone who may not see forecasts', () => {
            render(<Dashboard {...makeDashboard({ forecast: null })} />);

            expect(screen.queryByTestId('kpi-accuracy')).toBeNull();
            expect(screen.queryByTestId('panel-forecast')).toBeNull();
            expect(screen.getByTestId('kpi-sales')).toBeTruthy();
        });

        it('leaves out the money for someone who may not see sales', () => {
            render(
                <Dashboard
                    {...makeDashboard({
                        sales: null,
                        sales_trend: null,
                        categories: null,
                        top_movers: null,
                        slow_movers: null,
                    })}
                />,
            );

            expect(screen.queryByTestId('kpi-sales')).toBeNull();
            expect(screen.queryByTestId('panel-sales-trend')).toBeNull();
            expect(screen.queryByTestId('panel-categories')).toBeNull();
            expect(screen.queryByTestId('panel-top-movers')).toBeNull();
            expect(screen.queryByTestId('panel-slow-movers')).toBeNull();
            expect(screen.getByTestId('kpi-stock')).toBeTruthy();
        });

        it('says there is nothing to show when nothing may be shown', () => {
            render(<Dashboard {...emptyDashboard} />);

            expect(text('nothing-to-show')).toContain('Ask the Owner');
            expect(screen.queryByTestId('kpi-sales')).toBeNull();
        });

        it('does not say that to someone who has something to see', () => {
            render(<Dashboard {...makeDashboard()} />);

            expect(screen.queryByTestId('nothing-to-show')).toBeNull();
        });
    });
});
