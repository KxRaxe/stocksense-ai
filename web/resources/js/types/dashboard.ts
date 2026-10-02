import type {
    ForecastGranularity,
    ForecastPoint,
    HistoryPoint,
} from '@/types/forecasts';
import type { RiskLevel } from '@/types/replenishment';

/** The last 30 days against the 30 before. Revenue is in pesos. */
export type DashboardSales = {
    revenue: number;
    units: number;
    previous_revenue: number;
    previous_units: number;
    /** % change in revenue; null when the 30 days before had no sales. */
    revenue_change: number | null;
    units_change: number | null;
};

export type DashboardStock = {
    cost_value: number;
    retail_value: number;
    products: number;
    in_stock: number;
    out_of_stock: number;
};

export type DashboardRisk = {
    critical: number;
    low: number;
    watch: number;
    /** Critical plus low: what needs ordering now. */
    needs_attention: number;
};

export type TrendWeek = { period: string; revenue: number; units: number };

export type CategoryShare = {
    category_id: number;
    category: string;
    revenue: number;
    units: number;
    /** % of all revenue in the period. */
    share: number;
};

export type Mover = {
    product_id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    units: number;
    revenue: number;
};

export type SlowMover = {
    product_id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    units: number;
    on_hand: number;
    stock_value: number;
};

export type DashboardAlert = {
    id: number;
    product: { id: number; sku: string; name: string; unit: string };
    risk: RiskLevel;
    risk_label: string;
    on_hand: number;
    on_order: number;
    lead_time_demand: number;
    recommended_qty: number;
};

export type DashboardForecast = {
    has_run: boolean;
    granularity: ForecastGranularity;
    finished_at: string | null;
    age_days: number | null;
    stale: boolean;
    accuracy: {
        model: number | null;
        seasonal_naive: number | null;
        moving_average: number | null;
    } | null;
    history: HistoryPoint[];
    forecast: ForecastPoint[];
};

/** Each section is null when the person may not see it. */
export type DashboardProps = {
    period: { from: string; to: string; days: number };
    sales: DashboardSales | null;
    sales_trend: TrendWeek[] | null;
    categories: CategoryShare[] | null;
    top_movers: Mover[] | null;
    slow_movers: SlowMover[] | null;
    stock: DashboardStock | null;
    risk: DashboardRisk | null;
    alerts: DashboardAlert[] | null;
    forecast: DashboardForecast | null;
};
