import { Link } from '@inertiajs/react';
import { index } from '@/routes/recommendations';
import type { RecommendationView } from '@/types';

const views: { value: RecommendationView; label: string }[] = [
    { value: 'todo', label: 'To decide' },
    { value: 'overstock', label: 'Overstock' },
    { value: 'decided', label: 'Decided' },
];

/** To decide / Overstock / Decided. Each is a link, so the choice is in the URL. */
export default function ViewTabs({ view }: { view: RecommendationView }) {
    return (
        <div
            className="inline-flex rounded-lg bg-muted p-1 text-sm"
            role="tablist"
            aria-label="Show"
        >
            {views.map((option) => {
                const selected = option.value === view;

                return (
                    <Link
                        key={option.value}
                        href={index({ query: { view: option.value } })}
                        role="tab"
                        aria-selected={selected}
                        preserveScroll
                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                            selected
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                        data-test={`view-${option.value}`}
                    >
                        {option.label}
                    </Link>
                );
            })}
        </div>
    );
}
