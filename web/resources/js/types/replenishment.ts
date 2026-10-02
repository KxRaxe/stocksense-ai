import type { Option } from '@/types/catalog';

/** Mirrors App\Enums\RiskLevel. */
export type RiskLevel = 'critical' | 'low' | 'watch' | 'ok' | 'overstock';

/** Mirrors App\Enums\RecommendationStatus. */
export type RecommendationStatus =
    | 'pending'
    | 'accepted'
    | 'adjusted'
    | 'dismissed'
    | 'cancelled'
    | 'info';

/** Which group of recommendations the page is showing. */
export type RecommendationView = 'todo' | 'overstock' | 'decided';

export type RecommendationProduct = {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    moq: number;
    pack_size: number;
};

/** One recommendation, with the figures behind it. */
export type RecommendationRow = {
    id: number;
    product: RecommendationProduct;
    risk: RiskLevel;
    risk_label: string;
    status: RecommendationStatus;
    status_label: string;
    on_hand: number;
    on_order: number;
    lead_time_days: number;
    lead_time_demand: number;
    safety_stock: number;
    reorder_point: number;
    order_up_to: number;
    days_of_cover: number | null;
    recommended_qty: number;
    /** A calendar date, "2026-10-05"; null when there is nothing to order. */
    order_by_date: string | null;
    explanation: string;
    final_qty: number | null;
    decided_by: string | null;
    decided_at: string | null;
    note: string | null;
    snoozed_until: string | null;
    cancelled_at: string | null;
    is_pending: boolean;
    /** Accepted or adjusted, and so counted as on order. */
    is_ordered: boolean;
};

export type RecommendationCounts = {
    critical: number;
    low: number;
    watch: number;
    overstock: number;
    ordered: number;
};

export type RecommendationFilters = {
    view: RecommendationView;
    risk: RiskLevel | '';
    category: number | null;
    search: string;
};

/** The forecast the advice is built on. */
export type ForecastBasis = {
    id: number;
    granularity: 'week' | 'month';
    finished_at: string | null;
    age_days: number | null;
    stale: boolean;
};

export type RecommendationPage = {
    data: RecommendationRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type RecommendationsProps = {
    recommendations: RecommendationPage;
    counts: RecommendationCounts;
    filters: RecommendationFilters;
    categories: Option[];
    forecast: ForecastBasis | null;
    can: { decide: boolean };
};

/** What a person can do to a recommendation. */
export type DecisionKind = 'accept' | 'adjust' | 'dismiss' | 'cancel';

/** Mirrors App\Enums\NotificationType. */
export type NotificationKind =
    | 'critical_stock'
    | 'replenishment_digest'
    | 'forecast_run'
    | 'import_errors';

/** A notification as listed, in the bell and on the notifications page. */
export type NotificationItem = {
    id: string;
    type: string;
    title: string;
    message: string;
    /** A path inside the app. */
    url: string;
    read: boolean;
    created_at: string | null;
};

/** The shared `notifications` page prop behind the bell; null when signed out. */
export type NotificationsShared = {
    unread: number;
    recent: NotificationItem[];
};

export type DigestFrequency = 'daily' | 'weekly' | 'off';

/** One row of the notification settings. */
export type NotificationSetting = {
    type: NotificationKind;
    label: string;
    description: string;
    is_digest: boolean;
    mail: boolean;
    database: boolean;
    digest: DigestFrequency | null;
};

export type FrequencyOption = { value: DigestFrequency; label: string };
