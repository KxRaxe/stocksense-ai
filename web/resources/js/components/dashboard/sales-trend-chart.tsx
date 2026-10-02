import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { periodLabel } from '@/lib/forecast';
import {
    formatDate,
    formatMoney,
    formatMoneyCompact,
    formatNumber,
} from '@/lib/format';
import type { TrendWeek } from '@/types';

type TooltipProps = {
    active?: boolean;
    payload?: { payload: TrendWeek }[];
};

function WeekTooltip({ active, payload }: TooltipProps) {
    const week = payload?.[0]?.payload;

    if (!active || !week) {
        return null;
    }

    return (
        <div className="rounded-lg border bg-background p-3 text-sm shadow-md">
            <p className="font-medium">Week of {formatDate(week.period)}</p>
            <p>{formatMoney(week.revenue)}</p>
            <p className="text-muted-foreground">
                {formatNumber(week.units)} units
            </p>
        </div>
    );
}

/** Revenue for each of the last whole weeks. */
export default function SalesTrendChart({ weeks }: { weeks: TrendWeek[] }) {
    return (
        <figure className="space-y-2" data-test="sales-trend-chart">
            <div className="h-64 w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={weeks}
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
                                periodLabel(value, 'week')
                            }
                            tick={{ fontSize: 12 }}
                            minTickGap={24}
                        />
                        <YAxis
                            tick={{ fontSize: 12 }}
                            width={56}
                            tickFormatter={(value: number) =>
                                formatMoneyCompact(value)
                            }
                        />
                        <Tooltip
                            content={<WeekTooltip />}
                            cursor={{ fill: 'var(--muted)' }}
                            isAnimationActive={false}
                        />
                        <Bar
                            dataKey="revenue"
                            fill="var(--chart-1)"
                            radius={[3, 3, 0, 0]}
                            isAnimationActive={false}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <figcaption className="text-sm text-muted-foreground">
                Revenue for each of the last {weeks.length} whole weeks (Monday
                to Sunday).
            </figcaption>
        </figure>
    );
}
