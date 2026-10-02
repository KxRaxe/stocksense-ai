import { formatPercent } from '@/lib/forecast';
import { formatMoney } from '@/lib/format';
import type { CategoryShare } from '@/types';

/** Each category's share of the revenue, as a bar and a figure. */
export default function CategoryBreakdown({
    categories,
}: {
    categories: CategoryShare[];
}) {
    if (categories.length === 0) {
        return (
            <p
                className="text-sm text-muted-foreground"
                data-test="no-categories"
            >
                Nothing has sold in this period.
            </p>
        );
    }

    return (
        <ul className="space-y-3" data-test="category-breakdown">
            {categories.map((category) => (
                <li
                    key={category.category_id}
                    data-test={`category-${category.category_id}`}
                >
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="font-medium">{category.category}</span>
                        <span className="text-muted-foreground">
                            {formatMoney(category.revenue)} ·{' '}
                            {formatPercent(category.share)}
                        </span>
                    </div>
                    <div
                        className="mt-1 h-2 overflow-hidden rounded-full bg-muted"
                        role="presentation"
                    >
                        <div
                            className="h-full rounded-full"
                            style={{
                                width: `${Math.max(category.share, 1)}%`,
                                background: 'var(--chart-1)',
                            }}
                            data-test="category-bar"
                        />
                    </div>
                </li>
            ))}
        </ul>
    );
}
