import { Link } from '@inertiajs/react';
import { formatNumber } from '@/lib/format';
import { riskStyles } from '@/lib/replenishment';
import { cn } from '@/lib/utils';
import { index } from '@/routes/recommendations';
import type { RecommendationCounts, RecommendationFilters } from '@/types';

type Props = {
    counts: RecommendationCounts;
    filters: RecommendationFilters;
};

type Card = {
    key: keyof RecommendationCounts;
    label: string;
    hint: string;
    href: string;
    /** Whether this card is the one the list is currently narrowed to. */
    active: boolean;
};

/**
 * How many products are at each level. Each card is a link that narrows the
 * list to it, so the numbers are also the way in.
 */
export default function RecommendationSummary({ counts, filters }: Props) {
    const cards: Card[] = [
        {
            key: 'critical',
            label: 'Critical',
            hint: riskStyles.critical.meaning,
            href: index({ query: { risk: 'critical' } }).url,
            active: filters.view === 'todo' && filters.risk === 'critical',
        },
        {
            key: 'low',
            label: 'Low',
            hint: riskStyles.low.meaning,
            href: index({ query: { risk: 'low' } }).url,
            active: filters.view === 'todo' && filters.risk === 'low',
        },
        {
            key: 'watch',
            label: 'Watch',
            hint: riskStyles.watch.meaning,
            href: index({ query: { risk: 'watch' } }).url,
            active: filters.view === 'todo' && filters.risk === 'watch',
        },
        {
            key: 'overstock',
            label: 'Overstock',
            hint: riskStyles.overstock.meaning,
            href: index({ query: { view: 'overstock' } }).url,
            active: filters.view === 'overstock',
        },
        {
            key: 'ordered',
            label: 'On order',
            hint: 'Accepted and waiting to arrive',
            href: index({ query: { view: 'decided' } }).url,
            active: filters.view === 'decided',
        },
    ];

    return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            {cards.map((card) => (
                <Link
                    key={card.key}
                    href={card.href}
                    preserveScroll
                    title={card.hint}
                    className={cn(
                        'rounded-lg border p-3 transition-colors hover:bg-muted/50',
                        card.active && 'border-foreground/40 bg-muted/50',
                    )}
                    aria-current={card.active ? 'true' : undefined}
                    data-test={`count-${card.key}`}
                >
                    <div className="text-2xl font-semibold">
                        {formatNumber(counts[card.key])}
                    </div>
                    <div className="text-sm text-muted-foreground">
                        {card.label}
                    </div>
                </Link>
            ))}
        </div>
    );
}
