import { Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck, RefreshCw, TriangleAlert } from 'lucide-react';
import Heading from '@/components/heading';
import LinkTabs from '@/components/link-tabs';
import Pagination from '@/components/pagination';
import RecommendationCard from '@/components/recommendations/recommendation-card';
import RecommendationFilters from '@/components/recommendations/recommendation-filters';
import RecommendationSummary from '@/components/recommendations/recommendation-summary';
import { Button } from '@/components/ui/button';
import { index as forecastsIndex } from '@/routes/forecasts';
import { index, refresh } from '@/routes/recommendations';
import type {
    ForecastBasis,
    RecommendationFilters as Filters,
    RecommendationsProps,
    RecommendationView,
} from '@/types';

const views: { key: RecommendationView; label: string }[] = [
    { key: 'todo', label: 'To decide' },
    { key: 'overstock', label: 'Overstock' },
    { key: 'decided', label: 'Decided' },
];

const emptyText: Record<RecommendationView, string> = {
    todo: 'Nothing needs ordering right now.',
    overstock: 'No product has more stock than it is likely to sell soon.',
    decided: 'Nothing has been decided yet.',
};

function Basis({ forecast }: { forecast: ForecastBasis }) {
    const age =
        forecast.age_days === null
            ? ''
            : forecast.age_days === 0
              ? ' today'
              : ` ${forecast.age_days} ${forecast.age_days === 1 ? 'day' : 'days'} ago`;

    return (
        <p
            className={`flex items-start gap-2 text-sm ${
                forecast.stale
                    ? 'rounded-xl border-2 border-ink bg-low p-3 text-ink shadow-brutal-sm dark:border-transparent'
                    : 'text-muted-foreground'
            }`}
            data-test="forecast-basis"
        >
            {forecast.stale && (
                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            )}
            <span>
                Based on the{' '}
                {forecast.granularity === 'week' ? 'weekly' : 'monthly'}{' '}
                forecast made{age}.
                {forecast.stale &&
                    ' That is getting old, so the advice may be out of date: run a new forecast.'}
            </span>
        </p>
    );
}

function hasFilter(filters: Filters): boolean {
    return (
        filters.risk !== '' ||
        filters.category !== null ||
        filters.search !== ''
    );
}

export default function RecommendationsIndex({
    recommendations,
    counts,
    filters,
    categories,
    forecast,
    can,
}: RecommendationsProps) {
    return (
        <>
            <Head title="Recommendations" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Recommendations"
                        description="What to reorder, when, and why. These are suggestions: nothing is ordered for you, and every decision is recorded."
                    />
                    {can.decide && forecast && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    refresh.url(),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            data-test="refresh-button"
                        >
                            <RefreshCw />
                            Recalculate
                        </Button>
                    )}
                </div>

                {forecast === null ? (
                    <div
                        className="space-y-2 rounded-xl border-2 bg-card p-8 text-center shadow-brutal"
                        data-test="no-forecast"
                    >
                        <ClipboardCheck className="mx-auto size-8 text-muted-foreground" />
                        <p className="font-medium">
                            There are no recommendations yet.
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Recommendations are worked out from the sales
                            forecast, and there is not one yet.{' '}
                            <Link
                                href={forecastsIndex()}
                                className="underline underline-offset-4"
                            >
                                Go to Forecasts
                            </Link>
                            .
                        </p>
                    </div>
                ) : (
                    <>
                        <Basis forecast={forecast} />

                        <RecommendationSummary
                            counts={counts}
                            filters={filters}
                        />

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <LinkTabs
                                label="Show"
                                testPrefix="view"
                                current={filters.view}
                                tabs={views.map((view) => ({
                                    ...view,
                                    href: index({ query: { view: view.key } })
                                        .url,
                                }))}
                            />
                        </div>

                        <RecommendationFilters
                            url={index().url}
                            filters={filters}
                            categories={categories}
                        />

                        {recommendations.data.length === 0 ? (
                            <p
                                className="rounded-xl border-2 bg-card p-8 text-center text-sm text-muted-foreground shadow-brutal"
                                data-test="no-recommendations"
                            >
                                {hasFilter(filters)
                                    ? 'No products match.'
                                    : emptyText[filters.view]}
                            </p>
                        ) : (
                            <div
                                className="space-y-4"
                                data-test="recommendation-list"
                            >
                                {recommendations.data.map((row) => (
                                    <RecommendationCard
                                        key={row.id}
                                        row={row}
                                        canDecide={can.decide}
                                    />
                                ))}
                            </div>
                        )}

                        <Pagination paginator={recommendations} />

                        <p className="text-sm text-muted-foreground">
                            Reorder point is the stock level at which to order,
                            allowing for sales while the order arrives and a
                            safety margin for the service level of the category.
                            Quantities count what is already on order. Overstock
                            is for information only.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

RecommendationsIndex.layout = {
    breadcrumbs: [{ title: 'Recommendations', href: index() }],
};
