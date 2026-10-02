import { describe, expect, it } from 'vitest';
import {
    decisionSummary,
    headline,
    orderByLabel,
    orderingRules,
    timeAgo,
} from '@/lib/replenishment';
import { makeRecommendation } from '@/test/replenishment';
import type { RecommendationRow } from '@/types';

describe('orderByLabel', () => {
    it('says to order now when the date is today or has passed', () => {
        expect(orderByLabel('2026-10-05', '2026-10-05')).toBe('Order now');
        expect(orderByLabel('2026-10-01', '2026-10-05')).toBe('Order now');
    });

    it('gives the date when it is still ahead', () => {
        expect(orderByLabel('2026-10-09', '2026-10-05')).toBe(
            'Order by Oct 9, 2026',
        );
    });

    it('says nothing when there is nothing to order', () => {
        expect(orderByLabel(null, '2026-10-05')).toBeNull();
    });
});

describe('orderingRules', () => {
    it('is empty when any amount can be ordered', () => {
        expect(orderingRules(1, 1, 'pc')).toBeNull();
    });

    it('names the pack size', () => {
        expect(orderingRules(12, 1, 'pc')).toBe('Packs of 12');
    });

    it('names the minimum', () => {
        expect(orderingRules(1, 24, 'kg')).toBe('At least 24 kg');
    });

    it('names both', () => {
        expect(orderingRules(12, 24, 'pc')).toBe('Packs of 12, at least 24 pc');
    });
});

describe('timeAgo', () => {
    const now = new Date('2026-10-05T12:00:00Z');

    it.each([
        ['2026-10-05T11:59:40Z', 'just now'],
        ['2026-10-05T11:55:00Z', '5 minutes ago'],
        ['2026-10-05T11:00:00Z', '1 hour ago'],
        ['2026-10-05T07:00:00Z', '5 hours ago'],
        ['2026-10-04T10:00:00Z', 'yesterday'],
        ['2026-10-02T12:00:00Z', '3 days ago'],
    ])('describes %s as "%s"', (iso, expected) => {
        expect(timeAgo(iso, now)).toBe(expected);
    });

    it('gives the date once it is more than a week old', () => {
        expect(timeAgo('2026-09-20T12:00:00Z', now)).toBe('Sep 20, 2026');
    });

    it('treats a time slightly ahead of this clock as just now', () => {
        expect(timeAgo('2026-10-05T12:00:05Z', now)).toBe('just now');
    });

    it('is empty when there is no time', () => {
        expect(timeAgo(null, now)).toBe('');
    });
});

describe('decisionSummary', () => {
    const row = {
        status: 'accepted',
        recommended_qty: 130,
        final_qty: 130,
        decided_by: 'Pat Manager',
        decided_at: '2026-10-05T08:00:00+08:00',
        snoozed_until: null,
        cancelled_at: null,
        product: { unit: 'pc' },
    } as RecommendationRow;

    it('says who accepted what, and when', () => {
        expect(decisionSummary(row)).toBe(
            'Accepted by Pat Manager on Oct 5, 2026: 130 pc on order.',
        );
    });

    it('says what was changed when the quantity was adjusted', () => {
        expect(
            decisionSummary({ ...row, status: 'adjusted', final_qty: 200 }),
        ).toBe(
            'Accepted by Pat Manager on Oct 5, 2026 with 200 pc instead of 130.',
        );
    });

    it('says how long a dismissed product is left alone', () => {
        expect(
            decisionSummary({
                ...row,
                status: 'dismissed',
                final_qty: null,
                snoozed_until: '2026-10-12',
            }),
        ).toBe(
            'Dismissed by Pat Manager on Oct 5, 2026. Not recommended again until Oct 12, 2026.',
        );
    });

    it('says a cancelled order no longer counts as on order', () => {
        expect(
            decisionSummary({
                ...row,
                status: 'cancelled',
                cancelled_at: '2026-10-06T09:00:00+08:00',
            }),
        ).toBe(
            'Order of 130 pc cancelled on Oct 6, 2026. It no longer counts as on order.',
        );
    });

    it('has nothing to say about one still waiting', () => {
        expect(decisionSummary({ ...row, status: 'pending' })).toBeNull();
    });
});

describe('headline', () => {
    const today = '2026-10-05';

    it('says how much to order, and when, while it is open', () => {
        expect(
            headline(
                makeRecommendation({ order_by_date: '2026-10-05' }),
                today,
            ),
        ).toEqual({ main: 'Order 130 pc', detail: 'Order now' });

        expect(
            headline(
                makeRecommendation({ order_by_date: '2026-10-09' }),
                today,
            ),
        ).toEqual({ main: 'Order 130 pc', detail: 'Order by Oct 9, 2026' });
    });

    it('says no order is needed when the quantity is nothing', () => {
        expect(
            headline(
                makeRecommendation({ recommended_qty: 0, status: 'info' }),
                today,
            ),
        ).toEqual({ main: 'No order needed', detail: null });
    });

    it('says what is on order once accepted, not that it is time to order', () => {
        expect(
            headline(
                makeRecommendation({ status: 'accepted', final_qty: 130 }),
                today,
            ),
        ).toEqual({ main: '130 pc on order', detail: null });
    });

    it('says what was recommended when the quantity was changed', () => {
        expect(
            headline(
                makeRecommendation({ status: 'adjusted', final_qty: 1200 }),
                today,
            ),
        ).toEqual({ main: '1,200 pc on order', detail: '130 recommended' });
    });

    it.each(['dismissed', 'cancelled'] as const)(
        'only says what was recommended once %s',
        (status) => {
            expect(headline(makeRecommendation({ status }), today)).toEqual({
                main: 'Recommended 130 pc',
                detail: null,
            });
        },
    );
});
