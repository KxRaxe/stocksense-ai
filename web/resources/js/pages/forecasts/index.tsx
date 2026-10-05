import { Head, Link } from '@inertiajs/react';
import { LineChart } from 'lucide-react';
import ForecastFilters from '@/components/forecasts/forecast-filters';
import GranularityTabs from '@/components/forecasts/granularity-tabs';
import RunForecastButton from '@/components/forecasts/run-forecast-button';
import RunProgress from '@/components/forecasts/run-progress';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    formatDateTime,
    formatNumber,
    formatPercent,
    formatUnits,
} from '@/lib/format';
import {
    asOfLabel,
    formatChange,
    formatRange,
    periodLabel,
    periodNoun,
} from '@/lib/forecast';
import {
    accuracy,
    index,
    product as productForecast,
} from '@/routes/forecasts';
import type {
    ActiveRun,
    ForecastFilters as Filters,
    ForecastGranularity,
    ForecastProductPage,
    ForecastRunSummary,
    GranularityOption,
    Option,
} from '@/types';

type Props = {
    granularity: ForecastGranularity;
    granularities: GranularityOption[];
    run: ForecastRunSummary | null;
    activeRun: ActiveRun | null;
    failure: string | null;
    products: ForecastProductPage | null;
    periods: string[];
    filters: Filters;
    categories: Option[];
    can: { run: boolean };
};

function Summary({ run }: { run: ForecastRunSummary }) {
    const noun = periodNoun(run.granularity, run.horizon);
    const headline = run.headline;

    return (
        <div
            className="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-2"
            data-test="run-summary"
        >
            <div className="space-y-1">
                <p className="font-medium">
                    {run.granularity === 'week' ? 'Weekly' : 'Monthly'} forecast
                    {run.finished_at && (
                        <span className="font-normal text-muted-foreground">
                            {' '}
                            made {formatDateTime(run.finished_at)}
                        </span>
                    )}
                </p>
                <p className="text-muted-foreground">
                    Looks {run.horizon} {noun} ahead
                    {run.first_period && run.last_period
                        ? `, from ${periodLabel(run.first_period, run.granularity)} to ${periodLabel(run.last_period, run.granularity)}`
                        : ''}
                    , based on sales up to the {run.granularity} of{' '}
                    {asOfLabel(run.as_of)}.
                </p>
                {run.n_products !== null && (
                    <p className="text-muted-foreground">
                        {formatNumber(run.n_products)} products:{' '}
                        {formatNumber(run.n_model_products ?? 0)} forecast by
                        the model
                        {(run.n_low_confidence ?? 0) > 0 &&
                            `, ${formatNumber(run.n_low_confidence ?? 0)} with under a year of sales history (low confidence)`}
                        .
                    </p>
                )}
            </div>

            <div className="space-y-1">
                {headline && headline.model !== null ? (
                    <>
                        <p className="font-medium">
                            Typically off by {formatPercent(headline.model)} of
                            units sold
                        </p>
                        <p className="text-muted-foreground">
                            On recent {periodNoun(run.granularity)} the same
                            measure was {formatPercent(headline.seasonal_naive)}{' '}
                            for the same {run.granularity} last year and{' '}
                            {formatPercent(headline.moving_average)} for a
                            recent average.
                        </p>
                    </>
                ) : (
                    <p className="text-muted-foreground">
                        Accuracy could not be measured: there is not enough
                        history yet.
                    </p>
                )}
                <Button variant="link" className="h-auto p-0" asChild>
                    <Link
                        href={accuracy({
                            query: { granularity: run.granularity },
                        })}
                        data-test="see-accuracy"
                    >
                        See how accurate it has been
                    </Link>
                </Button>
            </div>
        </div>
    );
}

export default function ForecastsIndex({
    granularity,
    granularities,
    run,
    activeRun,
    failure,
    products,
    periods,
    filters,
    categories,
    can,
}: Props) {
    const noun = periodNoun(granularity, 1);
    const horizon = run?.horizon ?? periods.length;

    return (
        <>
            <Head title="Forecasts" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Forecasts"
                        description="What each product is expected to sell, and how far to trust it. Forecasts are advice: nothing is ordered for you."
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <GranularityTabs
                            granularity={granularity}
                            options={granularities}
                            href={(value) =>
                                index({ query: { granularity: value } }).url
                            }
                        />
                        {can.run && (
                            <RunForecastButton
                                granularity={granularity}
                                disabled={activeRun !== null}
                            />
                        )}
                    </div>
                </div>

                <RunProgress activeRun={activeRun} granularity={granularity} />

                {failure && !activeRun && (
                    <div
                        className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm"
                        role="alert"
                        data-test="run-failure"
                    >
                        <p className="font-medium">
                            The last {granularity}ly forecast did not finish.
                        </p>
                        <p>{failure}</p>
                    </div>
                )}

                {run === null || products === null ? (
                    !activeRun && (
                        <div
                            className="space-y-2 rounded-lg border p-8 text-center"
                            data-test="no-forecast"
                        >
                            <LineChart className="mx-auto size-8 text-muted-foreground" />
                            <p className="font-medium">
                                There is no {granularity}ly forecast yet.
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {can.run
                                    ? 'Press "Run forecast" above. The model learns from your sales history, so it helps to have a year or more of it.'
                                    : 'An Owner or Manager can run the first forecast.'}
                            </p>
                        </div>
                    )
                ) : (
                    <>
                        <Summary run={run} />

                        <ForecastFilters
                            url={index().url}
                            granularity={granularity}
                            filters={filters}
                            categories={categories}
                        />

                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            Product
                                        </TableHead>
                                        <TableHead>Category</TableHead>
                                        <TableHead className="text-right">
                                            Next {noun}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Next {horizon}{' '}
                                            {periodNoun(granularity, horizon)}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Last {horizon}{' '}
                                            {periodNoun(granularity, horizon)}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Change
                                        </TableHead>
                                        <TableHead className="pr-4">
                                            Confidence
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {products.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={7}
                                                className="p-6 text-center text-muted-foreground"
                                            >
                                                No products match.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {products.data.map((row) => (
                                        <TableRow
                                            key={row.id}
                                            data-test={`forecast-row-${row.id}`}
                                        >
                                            <TableCell className="pl-4">
                                                <Link
                                                    href={productForecast(
                                                        row.id,
                                                        {
                                                            query: {
                                                                granularity,
                                                            },
                                                        },
                                                    )}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {row.name}
                                                </Link>
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {row.sku}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {row.category}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatRange(
                                                    row.next,
                                                    row.next_lower,
                                                    row.next_upper,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatUnits(row.total)}
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground">
                                                {formatNumber(row.recent)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {row.change === null
                                                    ? '-'
                                                    : formatChange(row.change)}
                                            </TableCell>
                                            <TableCell className="pr-4">
                                                {row.low_confidence ? (
                                                    <Badge
                                                        variant="secondary"
                                                        data-test="low-confidence-badge"
                                                    >
                                                        Low
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="outline">
                                                        Good
                                                    </Badge>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <Pagination paginator={products} />

                        <p className="text-sm text-muted-foreground">
                            Figures are units. The range is where sales are
                            likely to fall 8 times in 10. Products with under a
                            year of sales history get a plain recent average and
                            are marked low confidence. Products that have never
                            sold are not forecast.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

ForecastsIndex.layout = {
    breadcrumbs: [{ title: 'Forecasts', href: index() }],
};
