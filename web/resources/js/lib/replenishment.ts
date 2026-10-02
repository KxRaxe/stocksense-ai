import { formatDate, formatNumber } from '@/lib/format';
import type { RecommendationRow, RiskLevel } from '@/types';

/** Today's date as YYYY-MM-DD in the browser's own time zone. */
export const todayLocal = (): string => new Date().toLocaleDateString('en-CA');

/** A risk level as a badge: its colours, and what it means in a few words. */
export const riskStyles: Record<
    RiskLevel,
    { className: string; meaning: string }
> = {
    critical: {
        className: 'border-transparent bg-red-600 text-white',
        meaning: 'May run out before a new order could arrive',
    },
    low: {
        className:
            'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
        meaning: 'At or below the reorder point',
    },
    watch: {
        className:
            'border-transparent bg-sky-500/15 text-sky-700 dark:text-sky-400',
        meaning: 'Will reach the reorder point within a week',
    },
    ok: {
        className: 'text-foreground',
        meaning: 'Enough stock for now',
    },
    overstock: {
        className:
            'border-transparent bg-violet-500/15 text-violet-700 dark:text-violet-400',
        meaning: 'More stock than is likely to sell soon',
    },
};

/**
 * When to order, in words: "Order now" once the date has come, otherwise the date.
 * `today` is passed in so the answer does not depend on the clock.
 */
export function orderByLabel(
    date: string | null,
    today: string = todayLocal(),
): string | null {
    if (date === null) {
        return null;
    }

    return date <= today ? 'Order now' : `Order by ${formatDate(date)}`;
}

/**
 * The line at the top of a card. While a recommendation is open it says how
 * much to order and when; once decided it says what was decided, because the
 * figures below are as they were when it was worked out.
 * `today` is passed in so the answer does not depend on the clock.
 */
export function headline(
    row: RecommendationRow,
    today: string = todayLocal(),
): { main: string; detail: string | null } {
    const unit = row.product.unit;

    if (row.status === 'accepted' || row.status === 'adjusted') {
        const quantity = row.final_qty ?? row.recommended_qty;

        return {
            main: `${formatNumber(quantity)} ${unit} on order`,
            detail:
                quantity !== row.recommended_qty
                    ? `${formatNumber(row.recommended_qty)} recommended`
                    : null,
        };
    }

    if (row.recommended_qty <= 0) {
        return { main: 'No order needed', detail: null };
    }

    const quantity = formatNumber(row.recommended_qty);

    if (row.status === 'dismissed' || row.status === 'cancelled') {
        return { main: `Recommended ${quantity} ${unit}`, detail: null };
    }

    return {
        main: `Order ${quantity} ${unit}`,
        detail: orderByLabel(row.order_by_date, today),
    };
}

/** "Packs of 12, at least 24", or null when any amount can be ordered. */
export function orderingRules(
    packSize: number,
    moq: number,
    unit: string,
): string | null {
    const parts: string[] = [];

    if (packSize > 1) {
        parts.push(`Packs of ${packSize}`);
    }

    if (moq > 1) {
        parts.push(
            `${parts.length > 0 ? 'at least' : 'At least'} ${moq} ${unit}`,
        );
    }

    return parts.length > 0 ? parts.join(', ') : null;
}

/** What was decided, for the line under a decided recommendation. */
export function decisionSummary(row: RecommendationRow): string | null {
    const quantity = `${row.final_qty ?? row.recommended_qty} ${row.product.unit}`;
    const when = row.decided_at
        ? ` on ${formatDate(row.decided_at.slice(0, 10))}`
        : '';
    const who = row.decided_by ? ` by ${row.decided_by}` : '';

    switch (row.status) {
        case 'accepted':
            return `Accepted${who}${when}: ${quantity} on order.`;
        case 'adjusted':
            return `Accepted${who}${when} with ${quantity} instead of ${row.recommended_qty}.`;
        case 'dismissed':
            return `Dismissed${who}${when}${
                row.snoozed_until
                    ? `. Not recommended again until ${formatDate(row.snoozed_until)}`
                    : ''
            }.`;
        case 'cancelled':
            return `Order of ${quantity} cancelled${
                row.cancelled_at
                    ? ` on ${formatDate(row.cancelled_at.slice(0, 10))}`
                    : ''
            }. It no longer counts as on order.`;
        default:
            return null;
    }
}

const relative = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

/**
 * "just now", "5 minutes ago", "yesterday"; older than a week, the date.
 * `now` is passed in so the answer does not depend on the clock.
 */
export function timeAgo(iso: string | null, now: Date = new Date()): string {
    if (iso === null) {
        return '';
    }

    const then = new Date(iso);
    const seconds = Math.round((then.getTime() - now.getTime()) / 1000);
    const past = -seconds;

    if (past < 60) {
        return 'just now';
    }

    if (past < 3600) {
        return relative.format(Math.round(seconds / 60), 'minute');
    }

    if (past < 86400) {
        return relative.format(Math.round(seconds / 3600), 'hour');
    }

    if (past < 7 * 86400) {
        return relative.format(Math.round(seconds / 86400), 'day');
    }

    return formatDate(then.toLocaleDateString('en-CA'));
}
