import type { Paginated } from '@/types/catalog';

export type ForecastGranularity = 'week' | 'month';

export type GranularityOption = { value: ForecastGranularity; label: string };

/**
 * The model's error against each yardstick as WAPE (total error as a % of
 * units sold), and how often actual sales fell inside the forecast range.
 */
export type Headline = {
    model: number | null;
    seasonal_naive: number | null;
    moving_average: number | null;
    coverage: number | null;
};

/** What a finished forecast run says about itself. */
export type ForecastRunSummary = {
    id: number;
    granularity: ForecastGranularity;
    horizon: number;
    model_version: string | null;
    finished_at: string | null;
    /** Start of the last period of sales history the run used (YYYY-MM-DD). */
    as_of: string | null;
    first_period: string | null;
    last_period: string | null;
    n_products: number | null;
    n_model_products: number | null;
    n_low_confidence: number | null;
    backtest_folds: number | null;
    headline: Headline | null;
};

export type ActiveRun = {
    id: number;
    status: 'queued' | 'running';
    status_label: string;
    started_at: string | null;
};

export type ForecastProductRow = {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    next: number;
    next_lower: number;
    next_upper: number;
    total: number;
    recent: number;
    /** % difference between the forecast and the same number of periods just gone; null if nothing sold. */
    change: number | null;
    low_confidence: boolean;
};

export type ForecastFilters = {
    search: string;
    category: number | null;
    confidence: '' | 'low';
    sort: 'name' | 'forecast';
};

export type ForecastProductPage = Paginated<ForecastProductRow>;

/** How far off a forecast was, measured by replaying recent weeks or months. */
export type MetricSet = {
    mae: number;
    rmse: number;
    mape: number | null;
    wape: number | null;
    n: number;
    coverage: number | null;
};

export type BaselineMetrics = {
    seasonal_naive: MetricSet;
    moving_average: MetricSet;
};

export type CategoryAccuracy = {
    name: string;
    model: MetricSet;
    seasonal_naive: MetricSet;
    moving_average: MetricSet;
};

export type FeatureImportance = { feature: string; importance: number };

export type PastRun = {
    id: number;
    finished_at: string | null;
    as_of: string | null;
    model_version: string | null;
    wape: number | null;
};

export type TrendPoint = {
    run: number;
    date: string | null;
    model: number | null;
    seasonal_naive: number | null;
    moving_average: number | null;
};

/** A past period's actual sales. */
export type HistoryPoint = { period: string; qty: number };

/** A forecast period: the median and the 10th and 90th percentile. */
export type ForecastPoint = {
    period: string;
    yhat: number;
    lower: number;
    upper: number;
};
