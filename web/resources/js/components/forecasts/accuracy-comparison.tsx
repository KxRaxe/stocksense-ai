import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber, formatPercent, formatUnits } from '@/lib/format';
import { periodNoun } from '@/lib/forecast';
import type { BaselineMetrics, ForecastGranularity, MetricSet } from '@/types';

type Props = {
    metrics: MetricSet;
    baselines: BaselineMetrics;
    granularity: ForecastGranularity;
};

type Column = 'model' | 'seasonal_naive' | 'moving_average';

/** The lowest value of a measure (lower is better), ignoring any that is missing. */
function best(values: Record<Column, number | null>): Column | null {
    const entries = (
        Object.entries(values) as [Column, number | null][]
    ).filter((entry): entry is [Column, number] => entry[1] !== null);

    if (entries.length === 0) {
        return null;
    }

    return entries.reduce((a, b) => (b[1] < a[1] ? b : a))[0];
}

/**
 * The model beside the two simple yardsticks it has to beat, on the same
 * recent periods. The best figure on each row is marked.
 */
export default function AccuracyComparison({
    metrics,
    baselines,
    granularity,
}: Props) {
    const columns: { key: Column; label: string; set: MetricSet }[] = [
        { key: 'model', label: 'Forecasting model', set: metrics },
        {
            key: 'seasonal_naive',
            label: `Same ${periodNoun(granularity, 1)} last year`,
            set: baselines.seasonal_naive,
        },
        {
            key: 'moving_average',
            label: 'Recent average',
            set: baselines.moving_average,
        },
    ];

    const rows: {
        key: string;
        label: string;
        help: string;
        value: (set: MetricSet) => number | null;
        format: (value: number | null) => string;
    }[] = [
        {
            key: 'wape',
            label: 'Error as % of units sold (WAPE)',
            help: 'Total miss divided by total sold. The fairest single number: busy products count for more.',
            value: (set) => set.wape,
            format: formatPercent,
        },
        {
            key: 'mape',
            label: 'Average % miss (MAPE)',
            help: 'The usual miss as a share of what sold, product by product. Periods with no sales are left out.',
            value: (set) => set.mape,
            format: formatPercent,
        },
        {
            key: 'mae',
            label: `Average miss, units per ${periodNoun(granularity, 1)} (MAE)`,
            help: 'How many units the forecast is typically off by.',
            value: (set) => set.mae,
            format: (value) => (value === null ? '-' : formatUnits(value)),
        },
        {
            key: 'rmse',
            label: 'Big misses weighed more (RMSE)',
            help: 'Like the average miss, but a few large misses count extra.',
            value: (set) => set.rmse,
            format: (value) => (value === null ? '-' : formatUnits(value)),
        },
    ];

    return (
        <div className="space-y-2">
            <div className="rounded-xl border-2 bg-card shadow-brutal">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-4">
                                Lower is better
                            </TableHead>
                            {columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className="text-right"
                                >
                                    {column.label}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => {
                            const winner = best({
                                model: row.value(metrics),
                                seasonal_naive: row.value(
                                    baselines.seasonal_naive,
                                ),
                                moving_average: row.value(
                                    baselines.moving_average,
                                ),
                            });

                            return (
                                <TableRow
                                    key={row.key}
                                    data-test={`accuracy-row-${row.key}`}
                                >
                                    <TableCell className="pl-4 whitespace-normal">
                                        <div className="font-medium">
                                            {row.label}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {row.help}
                                        </div>
                                    </TableCell>
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className="text-right"
                                            data-test={`${row.key}-${column.key}`}
                                        >
                                            <span
                                                className={
                                                    winner === column.key
                                                        ? 'font-semibold'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {row.format(
                                                    row.value(column.set),
                                                )}
                                            </span>
                                            {winner === column.key && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2"
                                                >
                                                    Best
                                                </Badge>
                                            )}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
            <p className="text-sm text-muted-foreground">
                Measured on {formatNumber(metrics.n)} product-
                {periodNoun(granularity)} the model had not seen when it made
                the forecast.
                {metrics.coverage !== null &&
                    ` Actual sales landed inside the likely range ${formatPercent(metrics.coverage)} of the time (the target is 80%).`}
            </p>
        </div>
    );
}
