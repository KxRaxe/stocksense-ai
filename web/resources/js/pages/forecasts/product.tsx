import { Head, Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import ForecastChart from '@/components/forecasts/forecast-chart';
import GranularityTabs from '@/components/forecasts/granularity-tabs';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatNumber } from '@/lib/format';
import {
    asOfLabel,
    formatRange,
    formatUnits,
    periodLabel,
    periodNoun,
} from '@/lib/forecast';
import { index, product as productForecast } from '@/routes/forecasts';
import type {
    ForecastGranularity,
    ForecastPoint,
    ForecastRunSummary,
    GranularityOption,
    HistoryPoint,
} from '@/types';

type Props = {
    product: {
        id: number;
        sku: string;
        name: string;
        category: string;
        unit: string;
        is_active: boolean;
    };
    granularity: ForecastGranularity;
    granularities: GranularityOption[];
    run: ForecastRunSummary | null;
    history: HistoryPoint[];
    forecast: ForecastPoint[];
    method: 'xgboost' | 'moving_average' | null;
    method_label: string | null;
    low_confidence: boolean;
    typical_error: number | null;
    history_periods: number | null;
};

function Stat({
    label,
    value,
    note,
}: {
    label: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight">
                {value}
            </div>
            {note && (
                <div className="mt-1 text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}

export default function ProductForecast({
    product,
    granularity,
    granularities,
    run,
    history,
    forecast,
    method_label: methodLabel,
    low_confidence: lowConfidence,
    typical_error: typicalError,
    history_periods: historyPeriods,
}: Props) {
    const noun = periodNoun(granularity, 1);
    const total = forecast.reduce((sum, point) => sum + point.yhat, 0);
    const first = forecast[0];

    return (
        <>
            <Head title={`Forecast: ${product.name}`} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={product.name}
                        description={`${product.sku} · ${product.category}${product.is_active ? '' : ' · archived'}`}
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <GranularityTabs
                            granularity={granularity}
                            options={granularities}
                            href={(value) =>
                                productForecast(product.id, {
                                    query: { granularity: value },
                                }).url
                            }
                        />
                        <Button variant="outline" asChild>
                            <Link
                                href={index({ query: { granularity } })}
                                data-test="back-to-forecasts"
                            >
                                All forecasts
                            </Link>
                        </Button>
                    </div>
                </div>

                {run === null && (
                    <div
                        className="rounded-lg border p-6 text-sm text-muted-foreground"
                        data-test="no-forecast"
                    >
                        There is no {granularity}ly forecast yet. Run one from
                        the Forecasts page.
                    </div>
                )}

                {run !== null && forecast.length === 0 && (
                    <div
                        className="rounded-lg border p-6 text-sm text-muted-foreground"
                        data-test="not-forecast"
                    >
                        This product is not in the latest {granularity}ly
                        forecast. Products are forecast once they have sold at
                        least once and while they are active.
                    </div>
                )}

                {lowConfidence && forecast.length > 0 && (
                    <div
                        className="flex items-start gap-3 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm"
                        role="alert"
                        data-test="low-confidence-warning"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div>
                            <p className="font-medium">
                                Low confidence: under a year of sales history
                            </p>
                            <p className="text-muted-foreground">
                                {historyPeriods !== null &&
                                    `This product has ${formatNumber(historyPeriods)} ${periodNoun(granularity, historyPeriods)} of history. `}
                                The model needs at least a year to learn a
                                product&apos;s seasons, so this forecast is just
                                the recent average. Use it as a rough guide.
                            </p>
                        </div>
                    </div>
                )}

                {forecast.length > 0 && first && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Stat
                                label={`Expected next ${noun}`}
                                value={`${formatUnits(first.yhat)} ${product.unit}`}
                                note={`Likely ${formatUnits(first.lower)} to ${formatUnits(first.upper)}`}
                            />
                            <Stat
                                label={`Expected over ${forecast.length} ${periodNoun(granularity, forecast.length)}`}
                                value={`${formatUnits(total)} ${product.unit}`}
                            />
                            <Stat
                                label={`Typical miss per ${noun}`}
                                value={
                                    typicalError === null
                                        ? '-'
                                        : `${formatUnits(typicalError)} ${product.unit}`
                                }
                                note="How far off past forecasts were"
                            />
                            <Stat
                                label="Made with"
                                value={methodLabel ?? '-'}
                                note={
                                    run?.finished_at
                                        ? `Run ${formatDateTime(run.finished_at)}`
                                        : undefined
                                }
                            />
                        </div>

                        <ForecastChart
                            history={history}
                            forecast={forecast}
                            granularity={granularity}
                            unit={product.unit}
                        />

                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-4">
                                            {granularity === 'week'
                                                ? 'Week of'
                                                : 'Month'}
                                        </TableHead>
                                        <TableHead className="pr-4 text-right">
                                            Expected (likely range)
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {forecast.map((point) => (
                                        <TableRow
                                            key={point.period}
                                            data-test={`forecast-period-${point.period}`}
                                        >
                                            <TableCell className="pl-4">
                                                {periodLabel(
                                                    point.period,
                                                    granularity,
                                                )}
                                            </TableCell>
                                            <TableCell className="pr-4 text-right">
                                                {formatRange(
                                                    point.yhat,
                                                    point.lower,
                                                    point.upper,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {run &&
                                `Based on sales up to the ${granularity} of ${asOfLabel(run.as_of)}. `}
                            Demand is expected to fall inside the likely range 8
                            times in 10, so an occasional miss is normal.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

ProductForecast.layout = {
    breadcrumbs: [
        { title: 'Forecasts', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Product', href: index() },
    ],
};
