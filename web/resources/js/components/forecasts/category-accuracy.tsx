import { formatPercent } from '@/lib/format';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { periodNoun } from '@/lib/forecast';
import type { CategoryAccuracy, ForecastGranularity } from '@/types';

type Props = {
    categories: CategoryAccuracy[];
    granularity: ForecastGranularity;
};

/** The model's error against each yardstick, category by category. */
export default function CategoryAccuracyView({
    categories,
    granularity,
}: Props) {
    if (categories.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No category has enough history to measure yet.
            </p>
        );
    }

    const data = categories.map((category) => ({
        name: category.name,
        'Forecasting model': category.model.wape,
        [`Same ${periodNoun(granularity, 1)} last year`]:
            category.seasonal_naive.wape,
        'Recent average': category.moving_average.wape,
    }));

    return (
        <div className="space-y-4">
            <div
                className="h-72 w-full"
                role="img"
                aria-label="Forecast error by category"
                data-test="category-chart"
            >
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data}>
                        <CartesianGrid
                            strokeDasharray="3 3"
                            stroke="var(--border)"
                            vertical={false}
                        />
                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                        <YAxis tick={{ fontSize: 12 }} width={44} unit="%" />
                        <Tooltip isAnimationActive={false} />
                        <Legend />
                        <Bar
                            dataKey="Forecasting model"
                            fill="var(--chart-2)"
                            isAnimationActive={false}
                        />
                        <Bar
                            dataKey={`Same ${periodNoun(granularity, 1)} last year`}
                            fill="var(--chart-3)"
                            isAnimationActive={false}
                        />
                        <Bar
                            dataKey="Recent average"
                            fill="var(--chart-4)"
                            isAnimationActive={false}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>

            <div className="rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="pl-4">
                                Error as % of units sold
                            </TableHead>
                            <TableHead className="text-right">Model</TableHead>
                            <TableHead className="text-right">
                                Same {periodNoun(granularity, 1)} last year
                            </TableHead>
                            <TableHead className="pr-4 text-right">
                                Recent average
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {categories.map((category) => (
                            <TableRow
                                key={category.name}
                                data-test={`category-row-${category.name}`}
                            >
                                <TableCell className="pl-4 font-medium">
                                    {category.name}
                                </TableCell>
                                <TableCell className="text-right">
                                    {formatPercent(category.model.wape)}
                                </TableCell>
                                <TableCell className="text-right">
                                    {formatPercent(
                                        category.seasonal_naive.wape,
                                    )}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    {formatPercent(
                                        category.moving_average.wape,
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
