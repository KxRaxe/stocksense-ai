import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RecommendationCard from '@/components/recommendations/recommendation-card';
import { makeRecommendation } from '@/test/replenishment';

// Errors the mocked Form hands to the dialog, like a failed server validation.
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
        <form
            action={action}
            method={method}
            className={className}
            data-test="decision-form"
        >
            {children({ processing: false, errors: formState.errors })}
        </form>
    ),
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

describe('RecommendationCard', () => {
    afterEach(() => {
        formState.errors = {};
    });

    it('names the product, links to it, and shows its risk', () => {
        render(<RecommendationCard row={makeRecommendation()} canDecide />);

        const link = screen.getByRole('link', { name: 'Nails' });

        expect(link.getAttribute('href')).toBe('/products/13');
        expect(screen.getByText('HW-1')).toBeTruthy();
        expect(screen.getByTestId('risk-badge').textContent).toBe('Critical');
    });

    it('says how much to order and that it is time to', () => {
        render(<RecommendationCard row={makeRecommendation()} canDecide />);

        expect(text('order-summary')).toBe('Order 130 pc · Order now');
    });

    it('gives the date when the order can wait', () => {
        render(
            <RecommendationCard
                row={makeRecommendation({
                    risk: 'watch',
                    risk_label: 'Watch',
                    order_by_date: '2999-01-01',
                })}
                canDecide
            />,
        );

        expect(text('order-summary')).toBe(
            'Order 130 pc · Order by Jan 1, 2999',
        );
    });

    it('says when nothing needs ordering', () => {
        render(
            <RecommendationCard
                row={makeRecommendation({
                    risk: 'overstock',
                    risk_label: 'Overstock',
                    status: 'info',
                    recommended_qty: 0,
                    order_by_date: null,
                    is_pending: false,
                })}
                canDecide
            />,
        );

        expect(text('order-summary')).toBe('No order needed');
    });

    it('mentions the pack size and the minimum', () => {
        render(
            <RecommendationCard
                row={makeRecommendation({
                    product: {
                        ...makeRecommendation().product,
                        pack_size: 12,
                        moq: 24,
                    },
                })}
                canDecide
            />,
        );

        expect(screen.getByText('Packs of 12, at least 24 pc')).toBeTruthy();
    });

    it('shows the figures behind the advice', () => {
        render(
            <RecommendationCard
                row={makeRecommendation({
                    on_hand: 1250,
                    on_order: 40,
                    lead_time_demand: 70.46,
                    safety_stock: 18,
                    reorder_point: 89,
                    days_of_cover: 1.6,
                })}
                canDecide
            />,
        );

        expect(text('on-hand')).toBe('1,250 pc');
        expect(text('on-order')).toBe('40 pc');
        expect(text('lead-time-demand')).toBe('70.5 pc');
        expect(text('safety-stock')).toBe('18 pc');
        expect(text('reorder-point')).toBe('89 pc');
        expect(text('days-of-cover')).toBe('2');
        expect(screen.getByText('Demand in 7 days')).toBeTruthy();
    });

    it('shows a dash when there is no way to tell the days of cover', () => {
        render(
            <RecommendationCard
                row={makeRecommendation({ days_of_cover: null })}
                canDecide
            />,
        );

        expect(text('days-of-cover')).toBe('-');
    });

    it('explains the recommendation in words', () => {
        render(<RecommendationCard row={makeRecommendation()} canDecide />);

        expect(text('explanation')).toContain('Order 130 now');
    });

    describe('what the person can do', () => {
        it('offers accept, change quantity and dismiss on one that is waiting', () => {
            render(<RecommendationCard row={makeRecommendation()} canDecide />);

            expect(screen.getByTestId('accept-button')).toBeTruthy();
            expect(screen.getByTestId('adjust-button')).toBeTruthy();
            expect(screen.getByTestId('dismiss-button')).toBeTruthy();
            expect(screen.queryByTestId('cancel-button')).toBeNull();
        });

        it('offers nothing to someone who may only look', () => {
            render(
                <RecommendationCard
                    row={makeRecommendation()}
                    canDecide={false}
                />,
            );

            expect(screen.queryByTestId('accept-button')).toBeNull();
            expect(screen.queryByTestId('adjust-button')).toBeNull();
            expect(screen.queryByTestId('dismiss-button')).toBeNull();
        });

        it.each(['accepted', 'adjusted'] as const)(
            'offers to cancel an order that was %s',
            (status) => {
                render(
                    <RecommendationCard
                        row={makeRecommendation({
                            status,
                            is_pending: false,
                            is_ordered: true,
                            final_qty: 130,
                        })}
                        canDecide
                    />,
                );

                expect(screen.getByTestId('cancel-button')).toBeTruthy();
                expect(screen.queryByTestId('accept-button')).toBeNull();
            },
        );

        it.each(['dismissed', 'cancelled'] as const)(
            'has nothing to do on one that was %s',
            (status) => {
                render(
                    <RecommendationCard
                        row={makeRecommendation({
                            status,
                            is_pending: false,
                            is_ordered: false,
                        })}
                        canDecide
                    />,
                );

                expect(screen.queryByRole('button')).toBeNull();
            },
        );
    });

    describe('a decision already made', () => {
        const accepted = makeRecommendation({
            status: 'accepted',
            status_label: 'Accepted',
            is_pending: false,
            is_ordered: true,
            final_qty: 130,
            decided_by: 'Pat Manager',
            decided_at: '2026-10-05T08:00:00+08:00',
            note: 'Phoned the supplier',
        });

        it('says who decided what, with their note', () => {
            render(<RecommendationCard row={accepted} canDecide />);

            expect(text('decision-summary')).toContain(
                'Accepted by Pat Manager on Oct 5, 2026: 130 pc on order.',
            );
            expect(text('decision-summary')).toContain(
                'Note: Phoned the supplier',
            );
            expect(text('status-badge')).toBe('Accepted');
        });

        it('says what is on order rather than that it is time to order', () => {
            render(<RecommendationCard row={accepted} canDecide />);

            expect(text('order-summary')).toBe('130 pc on order');
            expect(text('snapshot-note')).toBe(
                'Figures are as they were when this was recommended.',
            );
        });

        it('shows no decision line on one still waiting', () => {
            render(<RecommendationCard row={makeRecommendation()} canDecide />);

            expect(screen.queryByTestId('decision-summary')).toBeNull();
            expect(screen.queryByTestId('status-badge')).toBeNull();
            expect(screen.queryByTestId('snapshot-note')).toBeNull();
        });
    });

    describe('deciding', () => {
        it('opens no dialog until a button is pressed', () => {
            render(<RecommendationCard row={makeRecommendation()} canDecide />);

            expect(screen.queryByRole('dialog')).toBeNull();
        });

        it('opens the accept dialog, which posts to the accept address', async () => {
            const user = userEvent.setup();

            render(<RecommendationCard row={makeRecommendation()} canDecide />);
            await user.click(screen.getByTestId('accept-button'));

            const dialog = screen.getByRole('dialog');

            expect(
                within(dialog).getByText('Accept this recommendation'),
            ).toBeTruthy();
            expect(
                within(dialog)
                    .getByTestId('decision-form')
                    .getAttribute('action'),
            ).toBe('/recommendations/5/accept');
        });

        it('opens the right dialog for each button', async () => {
            const user = userEvent.setup();

            render(<RecommendationCard row={makeRecommendation()} canDecide />);

            await user.click(screen.getByTestId('adjust-button'));
            expect(
                screen.getByTestId('decision-form').getAttribute('action'),
            ).toBe('/recommendations/5/adjust');

            await user.click(screen.getByRole('button', { name: 'Back' }));
            await user.click(screen.getByTestId('dismiss-button'));
            expect(
                screen.getByTestId('decision-form').getAttribute('action'),
            ).toBe('/recommendations/5/dismiss');
        });

        it('closes the dialog with Back', async () => {
            const user = userEvent.setup();

            render(<RecommendationCard row={makeRecommendation()} canDecide />);
            await user.click(screen.getByTestId('accept-button'));
            await user.click(screen.getByRole('button', { name: 'Back' }));

            expect(screen.queryByRole('dialog')).toBeNull();
        });
    });
});
