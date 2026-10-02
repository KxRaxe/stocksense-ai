import { Head, Link, router } from '@inertiajs/react';
import AccuracyComparison from '@/components/forecasts/accuracy-comparison';
import AccuracyTrend from '@/components/forecasts/accuracy-trend';
import CategoryAccuracyView from '@/components/forecasts/category-accuracy';
import FeatureBars from '@/components/forecasts/feature-bars';
import GranularityTabs from '@/components/forecasts/granularity-tabs';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import { formatDateTime, formatNumber } from '@/lib/format';
import { asOfLabel, verdict } from '@/lib/forecast';
import { accuracy, index } from '@/routes/forecasts';
import type {
    BaselineMetrics,
    CategoryAccuracy,
    FeatureImportance,
    ForecastGranularity,
    ForecastRunSummary,
    GranularityOption,
    MetricSet,
    PastRun,
    TrendPoint,
} from '@/types';

type Props = {
    granularity: ForecastGranularity;
    granularities: GranularityOption[];
    run:
        | (ForecastRunSummary & {
              metrics: MetricSet | null;
              baseline_metrics: BaselineMetrics | null;
              per_category: CategoryAccuracy[];
              feature_importance: FeatureImportance[];
          })
        | null;
    runs: PastRun[];
    trend: TrendPoint[];
    can: { run: boolean };
};

function Section({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div>
                <h3 className="text-base font-semibold">{title}</h3>
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

export default function ForecastAccuracy({
    granularity,
    granularities,
    run,
    runs,
    trend,
}: Props) {
    return (
        <>
            <Head title="Forecast accuracy" />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Forecast accuracy"
                        description="How well the forecasts would have done on recent history, against two simple ways of guessing. A forecast earns its place only by beating them."
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <GranularityTabs
                            granularity={granularity}
                            options={granularities}
                            href={(value) =>
                                accuracy({ query: { granularity: value } }).url
                            }
                        />
                        <Button variant="outline" asChild>
                            <Link href={index({ query: { granularity } })}>
                                Back to forecasts
                            </Link>
                        </Button>
                    </div>
                </div>

                {run === null ? (
                    <div
                        className="rounded-lg border p-8 text-center text-sm text-muted-foreground"
                        data-test="no-accuracy"
                    >
                        There is no {granularity}ly forecast to measure yet. Run
                        one from the Forecasts page.
                    </div>
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <p
                                className="max-w-3xl text-base font-medium"
                                data-test="verdict"
                            >
                                {verdict(run.headline, granularity)}
                            </p>
                            {runs.length > 1 && (
                                <label className="flex items-center gap-2 text-sm">
                                    <span className="text-muted-foreground">
                                        Forecast run
                                    </span>
                                    <NativeSelect
                                        value={run.id}
                                        onChange={(event) =>
                                            router.get(
                                                accuracy({
                                                    query: {
                                                        granularity,
                                                        run: event.target.value,
                                                    },
                                                }).url,
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    replace: true,
                                                },
                                            )
                                        }
                                        aria-label="Forecast run"
                                        className="w-72"
                                        data-test="run-select"
                                    >
                                        {runs.map((past) => (
                                            <option
                                                key={past.id}
                                                value={past.id}
                                            >
                                                {past.finished_at
                                                    ? formatDateTime(
                                                          past.finished_at,
                                                      )
                                                    : `Run ${past.id}`}
                                                {past.wape !== null
                                                    ? ` (${past.wape.toFixed(1)}% off)`
                                                    : ''}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </label>
                            )}
                        </div>

                        {run.metrics && run.baseline_metrics ? (
                            <Section
                                title="The model against the yardsticks"
                                description={`Each forecast was replayed: the model was shown only what was known at the time, asked to forecast the next ${run.horizon} ${granularity}s, and compared with what really sold. This was repeated ${run.backtest_folds} times, each further along.`}
                            >
                                <AccuracyComparison
                                    metrics={run.metrics}
                                    baselines={run.baseline_metrics}
                                    granularity={granularity}
                                />
                            </Section>
                        ) : (
                            <div
                                className="rounded-lg border p-6 text-sm text-muted-foreground"
                                data-test="not-measured"
                            >
                                Accuracy could not be measured for this run:
                                products need at least a year of sales history
                                to be tested.
                            </div>
                        )}

                        <Section
                            title="By category"
                            description="Some kinds of product are easier to forecast than others. Slow, irregular sellers and seasonal ones are the hardest."
                        >
                            <CategoryAccuracyView
                                categories={run.per_category}
                                granularity={granularity}
                            />
                        </Section>

                        <Section
                            title="What the model looks at"
                            description="The share of the model's decisions that each clue accounts for. A big share does not mean it is a cause, only that the model leans on it."
                        >
                            <FeatureBars
                                features={run.feature_importance}
                                granularity={granularity}
                            />
                        </Section>

                        <Section
                            title="Over time"
                            description="Error as a percentage of units sold, run by run. Lower is better."
                        >
                            <AccuracyTrend
                                trend={trend}
                                granularity={granularity}
                            />
                        </Section>

                        <p
                            className="text-sm text-muted-foreground"
                            data-test="run-details"
                        >
                            Run {run.id}
                            {run.model_version && ` · ${run.model_version}`}
                            {run.as_of &&
                                ` · sales up to the ${granularity} of ${asOfLabel(run.as_of)}`}
                            {run.n_products !== null &&
                                ` · ${formatNumber(run.n_products)} products (${formatNumber(run.n_model_products ?? 0)} by the model, ${formatNumber(run.n_low_confidence ?? 0)} by a recent average)`}
                            .
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

ForecastAccuracy.layout = {
    breadcrumbs: [
        { title: 'Forecasts', href: index() },
        { title: 'Accuracy', href: accuracy() },
    ],
};
