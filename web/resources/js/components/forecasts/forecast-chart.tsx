import {
    Area,
    CartesianGrid,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatDate, formatUnits } from '@/lib/format';
import { periodLabel } from '@/lib/forecast';
import type { ForecastGranularity, ForecastPoint, HistoryPoint } from '@/types';

export type ChartRow = {
    period: string;
    /** Units actually sold. */
    actual?: number;
    /** The forecast's median. */
    forecast?: number;
    /** The forecast's 10th and 90th percentile, drawn as a band. */
    band?: [number, number];
};

/**
 * Past sales followed by the forecast, as one series of rows for the chart.
 *
 * The forecast line also touches the last actual value, so the two lines join
 * up instead of leaving a gap; that joining point has no band.
 */
export function buildChartData(
    history: HistoryPoint[],
    forecast: ForecastPoint[],
): ChartRow[] {
    const rows: ChartRow[] = history.map((point) => ({
        period: point.period,
        actual: point.qty,
    }));

    if (rows.length > 0 && forecast.length > 0) {
        rows[rows.length - 1].forecast = rows[rows.length - 1].actual;
    }

    for (const point of forecast) {
        rows.push({
            period: point.period,
            forecast: point.yhat,
            band: [point.lower, point.upper],
        });
    }

    return rows;
}

type TooltipProps = {
    active?: boolean;
    payload?: { payload: ChartRow }[];
};

function ChartTooltip({ active, payload }: TooltipProps) {
    const row = payload?.[0]?.payload;

    if (!active || !row) {
        return null;
    }

    return (
        <div className="rounded-lg border-2 bg-card p-3 text-sm shadow-brutal-sm">
            <p className="font-medium">{formatDate(row.period)}</p>
            {row.band ? (
                <p>
                    Expected {formatUnits(row.forecast ?? 0)} (likely{' '}
                    {formatUnits(row.band[0])}-{formatUnits(row.band[1])})
                </p>
            ) : (
                <p>Sold {formatUnits(row.actual ?? 0)}</p>
            )}
        </div>
    );
}

type Props = {
    history: HistoryPoint[];
    forecast: ForecastPoint[];
    granularity: ForecastGranularity;
    unit: string;
};

/**
 * What a product sold lately, then what is expected next, with the range the
 * demand is likely to fall in (it should land inside about 8 times in 10).
 */
export default function ForecastChart({
    history,
    forecast,
    granularity,
    unit,
}: Props) {
    const data = buildChartData(history, forecast);

    return (
        <figure className="space-y-3" data-test="forecast-chart">
            <div className="h-72 w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart
                        data={data}
                        margin={{ top: 8, right: 8, bottom: 0, left: 0 }}
                    >
                        <CartesianGrid
                            strokeDasharray="3 3"
                            stroke="var(--border)"
                            vertical={false}
                        />
                        <XAxis
                            dataKey="period"
                            tickFormatter={(value: string) =>
                                periodLabel(value, granularity)
                            }
                            tick={{ fontSize: 12 }}
                            minTickGap={24}
                        />
                        <YAxis
                            tick={{ fontSize: 12 }}
                            width={44}
                            allowDecimals={false}
                        />
                        <Tooltip
                            content={<ChartTooltip />}
                            isAnimationActive={false}
                        />
                        <Area
                            dataKey="band"
                            stroke="none"
                            fill="var(--chart-2)"
                            fillOpacity={0.2}
                            isAnimationActive={false}
                            connectNulls={false}
                        />
                        <Line
                            dataKey="actual"
                            stroke="var(--chart-3)"
                            strokeWidth={2}
                            dot={false}
                            isAnimationActive={false}
                            connectNulls={false}
                        />
                        <Line
                            dataKey="forecast"
                            stroke="var(--chart-2)"
                            strokeWidth={2}
                            strokeDasharray="6 4"
                            dot={{ r: 3 }}
                            isAnimationActive={false}
                            connectNulls={false}
                        />
                    </ComposedChart>
                </ResponsiveContainer>
            </div>
            <figcaption className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-muted-foreground">
                <span className="flex items-center gap-2">
                    <span
                        className="inline-block h-0.5 w-5"
                        style={{ background: 'var(--chart-3)' }}
                    />
                    Sold ({unit} per {granularity})
                </span>
                <span className="flex items-center gap-2">
                    <span
                        className="inline-block h-0.5 w-5 border-t-2 border-dashed"
                        style={{ borderColor: 'var(--chart-2)' }}
                    />
                    Expected
                </span>
                <span className="flex items-center gap-2">
                    <span
                        className="inline-block h-3 w-5 rounded-sm"
                        style={{
                            background: 'var(--chart-2)',
                            opacity: 0.25,
                        }}
                    />
                    Likely range (8 times in 10)
                </span>
            </figcaption>
        </figure>
    );
}
