import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatDate } from '@/lib/format';
import { periodNoun } from '@/lib/forecast';
import type { ForecastGranularity, TrendPoint } from '@/types';

type Props = {
    trend: TrendPoint[];
    granularity: ForecastGranularity;
};

/** The model's error and the yardsticks' over the last runs, oldest first. */
export default function AccuracyTrend({ trend, granularity }: Props) {
    if (trend.length < 2) {
        return (
            <p className="text-sm text-muted-foreground">
                A chart appears here once there have been two forecast runs.
            </p>
        );
    }

    const last = `Same ${periodNoun(granularity, 1)} last year`;
    const data = trend.map((point) => ({
        label: point.date ? formatDate(point.date) : `Run ${point.run}`,
        'Forecasting model': point.model,
        [last]: point.seasonal_naive,
        'Recent average': point.moving_average,
    }));

    return (
        <div
            className="h-64 w-full"
            role="img"
            aria-label="Forecast error over time"
            data-test="trend-chart"
        >
            <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data}>
                    <CartesianGrid
                        strokeDasharray="3 3"
                        stroke="var(--border)"
                        vertical={false}
                    />
                    <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                    <YAxis tick={{ fontSize: 12 }} width={44} unit="%" />
                    <Tooltip isAnimationActive={false} />
                    <Legend />
                    <Line
                        dataKey="Forecasting model"
                        stroke="var(--chart-2)"
                        strokeWidth={2}
                        isAnimationActive={false}
                    />
                    <Line
                        dataKey={last}
                        stroke="var(--chart-3)"
                        isAnimationActive={false}
                    />
                    <Line
                        dataKey="Recent average"
                        stroke="var(--chart-4)"
                        isAnimationActive={false}
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
}
