import { Head, Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import AlertsList from '@/components/dashboard/alerts-list';
import CategoryBreakdown from '@/components/dashboard/category-breakdown';
import KpiCard from '@/components/dashboard/kpi-card';
import MoversTable from '@/components/dashboard/movers-table';
import SalesTrendChart from '@/components/dashboard/sales-trend-chart';
import ForecastChart from '@/components/forecasts/forecast-chart';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatChange, formatPercent } from '@/lib/forecast';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { dashboard } from '@/routes';
import { accuracy, index as forecastsIndex } from '@/routes/forecasts';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as recommendationsIndex } from '@/routes/recommendations';
import type { DashboardForecast, DashboardProps } from '@/types';

function Panel({
    title,
    description,
    children,
    testId,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    testId: string;
}) {
    return (
        <section className="space-y-3 rounded-lg border p-4" data-test={testId}>
            <div>
                <h3 className="font-medium">{title}</h3>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function AccuracyCard({ forecast }: { forecast: DashboardForecast }) {
    if (!forecast.has_run) {
        return (
            <KpiCard
                label="Forecast accuracy"
                value="No forecast yet"
                href={forecastsIndex()}
                testId="kpi-accuracy"
            >
                <p>Run one on the Forecasts page.</p>
            </KpiCard>
        );
    }

    const measured = forecast.accuracy?.model ?? null;

    return (
        <KpiCard
            label="Typical forecast error"
            value={measured === null ? 'Not measured' : formatPercent(measured)}
            href={accuracy()}
            testId="kpi-accuracy"
        >
            {measured === null ? (
                <p>There is not enough history to measure it yet.</p>
            ) : (
                <>
                    <p>Of units sold, week by week.</p>
                    <p>
                        Same week last year:{' '}
                        {formatPercent(
                            forecast.accuracy?.seasonal_naive ?? null,
                        )}
                    </p>
                </>
            )}
        </KpiCard>
    );
}

export default function Dashboard({
    period,
    sales,
    sales_trend,
    categories,
    top_movers,
    slow_movers,
    stock,
    risk,
    alerts,
    forecast,
}: DashboardProps) {
    const nothingToShow = [
        sales,
        stock,
        risk,
        forecast,
        sales_trend,
        alerts,
    ].every((section) => section === null);

    return (
        <>
            <Head title="Dashboard" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Dashboard"
                    description={`How the shop is doing. Sales are for the last ${period.days} days, ${formatDate(period.from)} to ${formatDate(period.to)}.`}
                />

                {nothingToShow && (
                    <p
                        className="rounded-lg border p-8 text-center text-sm text-muted-foreground"
                        data-test="nothing-to-show"
                    >
                        There is nothing for your account to show here yet. Ask
                        the Owner which parts of StockSense AI you should have
                        access to.
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {sales && (
                        <KpiCard
                            label={`Sales, last ${period.days} days`}
                            value={formatMoney(sales.revenue)}
                            testId="kpi-sales"
                        >
                            <p data-test="sales-change">
                                {sales.revenue_change === null
                                    ? `No sales in the ${period.days} days before`
                                    : `${formatChange(sales.revenue_change)} on the ${period.days} days before`}
                            </p>
                            <p>{formatNumber(sales.units)} units sold</p>
                        </KpiCard>
                    )}

                    {stock && (
                        <KpiCard
                            label="Stock value (at cost)"
                            value={formatMoney(stock.cost_value)}
                            href={inventoryIndex()}
                            testId="kpi-stock"
                        >
                            <p>
                                {formatNumber(stock.in_stock)} of{' '}
                                {formatNumber(stock.products)} products in stock
                            </p>
                            {stock.out_of_stock > 0 && (
                                <p data-test="out-of-stock">
                                    {formatNumber(stock.out_of_stock)} out of
                                    stock
                                </p>
                            )}
                        </KpiCard>
                    )}

                    {risk && (
                        <KpiCard
                            label="Needs ordering"
                            value={formatNumber(risk.needs_attention)}
                            tone={
                                risk.critical > 0
                                    ? 'bad'
                                    : risk.needs_attention > 0
                                      ? 'warning'
                                      : 'good'
                            }
                            href={recommendationsIndex()}
                            testId="kpi-risk"
                        >
                            <p>
                                {formatNumber(risk.critical)} critical,{' '}
                                {formatNumber(risk.low)} low
                            </p>
                            <p>{formatNumber(risk.watch)} to watch</p>
                        </KpiCard>
                    )}

                    {forecast && <AccuracyCard forecast={forecast} />}
                </div>

                {forecast?.stale && (
                    <p
                        className="flex items-start gap-2 rounded-lg border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                        data-test="stale-forecast"
                    >
                        <TriangleAlert className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        The forecast is {forecast.age_days} days old, so the
                        advice built on it may be out of date.
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    {sales_trend && (
                        <div className="lg:col-span-2">
                            <Panel
                                title="Sales by week"
                                testId="panel-sales-trend"
                            >
                                <SalesTrendChart weeks={sales_trend} />
                            </Panel>
                        </div>
                    )}

                    {categories && (
                        <Panel
                            title="Where the sales come from"
                            description={`Share of revenue by category, last ${period.days} days.`}
                            testId="panel-categories"
                        >
                            <CategoryBreakdown categories={categories} />
                        </Panel>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    {forecast && (
                        <div className="lg:col-span-2">
                            <Panel
                                title="Units sold and expected"
                                description="All products together, week by week."
                                testId="panel-forecast"
                            >
                                {forecast.has_run &&
                                forecast.forecast.length > 0 ? (
                                    <>
                                        <ForecastChart
                                            history={forecast.history}
                                            forecast={forecast.forecast}
                                            granularity="week"
                                            unit="units"
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            The range adds up each
                                            product&apos;s own range, so it is
                                            wider than it needs to be for the
                                            total.
                                        </p>
                                    </>
                                ) : (
                                    <p
                                        className="text-sm text-muted-foreground"
                                        data-test="no-forecast"
                                    >
                                        There is no weekly forecast yet.{' '}
                                        <Link
                                            href={forecastsIndex()}
                                            className="underline underline-offset-4"
                                        >
                                            Go to Forecasts
                                        </Link>
                                        .
                                    </p>
                                )}
                            </Panel>
                        </div>
                    )}

                    {alerts && risk && (
                        <Panel
                            title="Order soon"
                            description="The most urgent products."
                            testId="panel-alerts"
                        >
                            <AlertsList
                                alerts={alerts}
                                total={risk.needs_attention}
                            />
                        </Panel>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {top_movers && (
                        <Panel
                            title="Selling best"
                            description={`Most units sold in the last ${period.days} days.`}
                            testId="panel-top-movers"
                        >
                            <MoversTable kind="top" rows={top_movers} />
                        </Panel>
                    )}

                    {slow_movers && (
                        <Panel
                            title="Selling slowest"
                            description="In stock but barely selling: money sitting on the shelf."
                            testId="panel-slow-movers"
                        >
                            <MoversTable kind="slow" rows={slow_movers} />
                        </Panel>
                    )}
                </div>

                {forecast?.has_run && (
                    <div className="flex">
                        <Button variant="link" className="h-auto p-0" asChild>
                            <Link href={accuracy()}>
                                See how accurate the forecasts have been
                            </Link>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
