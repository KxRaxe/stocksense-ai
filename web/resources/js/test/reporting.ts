import type {
    AuditEntry,
    DashboardProps,
    ReportShowProps,
    SettingGroup,
} from '@/types';

/** A dashboard as the Owner sees it, for a small shop. Change what a test cares about. */
export function makeDashboard(
    changes: Partial<DashboardProps> = {},
): DashboardProps {
    return {
        period: { from: '2026-09-06', to: '2026-10-05', days: 30 },
        sales: {
            revenue: 2300,
            units: 130,
            previous_revenue: 1000,
            previous_units: 20,
            revenue_change: 130,
            units_change: 550,
        },
        sales_trend: [
            { period: '2026-09-14', revenue: 800, units: 100 },
            { period: '2026-09-21', revenue: 0, units: 0 },
            { period: '2026-09-28', revenue: 1000, units: 20 },
        ],
        categories: [
            {
                category_id: 1,
                category: 'Food',
                revenue: 1500,
                units: 30,
                share: 65.2,
            },
            {
                category_id: 2,
                category: 'Hardware',
                revenue: 800,
                units: 100,
                share: 34.8,
            },
        ],
        top_movers: [
            {
                product_id: 2,
                sku: 'H-1',
                name: 'Nails',
                category: 'Hardware',
                unit: 'pc',
                units: 100,
                revenue: 800,
            },
        ],
        slow_movers: [
            {
                product_id: 3,
                sku: 'H-2',
                name: 'Paint',
                category: 'Hardware',
                unit: 'pc',
                units: 0,
                on_hand: 10,
                stock_value: 1000,
            },
        ],
        stock: {
            cost_value: 7500,
            retail_value: 10500,
            products: 5,
            in_stock: 4,
            out_of_stock: 1,
        },
        risk: { critical: 1, low: 0, watch: 2, needs_attention: 1 },
        alerts: [
            {
                id: 9,
                product: { id: 5, sku: 'F-3', name: 'Soap', unit: 'pc' },
                risk: 'critical',
                risk_label: 'Critical',
                on_hand: 10,
                on_order: 0,
                lead_time_demand: 70,
                recommended_qty: 130,
            },
        ],
        forecast: {
            has_run: true,
            granularity: 'week',
            finished_at: '2026-10-01T02:01:00+08:00',
            age_days: 4,
            stale: false,
            accuracy: { model: 16, seasonal_naive: 20, moving_average: 18 },
            history: [
                { period: '2026-09-21', qty: 0 },
                { period: '2026-09-28', qty: 20 },
            ],
            forecast: [
                { period: '2026-10-05', yhat: 100, lower: 80, upper: 120 },
                { period: '2026-10-12', yhat: 100, lower: 80, upper: 120 },
            ],
        },
        ...changes,
    };
}

/** A dashboard with every section withheld, as for someone with no permissions. */
export const emptyDashboard: DashboardProps = makeDashboard({
    sales: null,
    sales_trend: null,
    categories: null,
    top_movers: null,
    slow_movers: null,
    stock: null,
    risk: null,
    alerts: null,
    forecast: null,
});

/** A sales report page, with two products. */
export function makeReport(
    changes: Partial<ReportShowProps> = {},
): ReportShowProps {
    return {
        report: {
            key: 'sales',
            title: 'Sales',
            description: 'Units and revenue for each product.',
            filters: ['date', 'category'],
        },
        reports: [
            { key: 'sales', title: 'Sales' },
            { key: 'inventory', title: 'Inventory status' },
        ],
        filters: {
            from: '2026-09-06',
            to: '2026-10-05',
            category: null,
            granularity: 'week',
        },
        filter_errors: {},
        subtitle: '6 Sep 2026 to 5 Oct 2026 · All categories',
        columns: [
            { key: 'name', label: 'Product', type: 'text' },
            { key: 'units', label: 'Units sold', type: 'integer' },
            { key: 'revenue', label: 'Revenue', type: 'money' },
            { key: 'share', label: 'Share of revenue', type: 'percent' },
        ],
        rows: {
            data: [
                { name: 'Rice', units: 30, revenue: 1500, share: 65.2 },
                { name: 'Nails', units: 100, revenue: 800, share: 34.8 },
            ],
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 2,
            total: 2,
            prev_page_url: null,
            next_page_url: null,
        },
        summary: [
            { label: 'Revenue', value: 2300, type: 'money' },
            { label: 'Best seller', value: 'Rice', type: 'text' },
        ],
        totals: { name: 'Total', units: 130, revenue: 2300, share: 100 },
        notes: ['Read this first.'],
        categories: [
            { id: 1, name: 'Food' },
            { id: 2, name: 'Hardware' },
        ],
        exports: [
            {
                format: 'xlsx',
                label: 'Excel',
                url: '/reports/sales/export/xlsx?from=2026-09-06&to=2026-10-05',
            },
            {
                format: 'pdf',
                label: 'PDF',
                url: '/reports/sales/export/pdf?from=2026-09-06&to=2026-10-05',
            },
        ],
        ...changes,
    };
}

export function makeSettingGroups(): SettingGroup[] {
    return [
        {
            name: 'Stock advice',
            settings: [
                {
                    key: 'review_days',
                    label: 'Review period',
                    help: 'How often you place orders.',
                    type: 'integer',
                    unit: 'days',
                    min: 1,
                    max: 60,
                    choices: [],
                    value: 14,
                    default: 7,
                    is_default: false,
                },
                {
                    key: 'default_service_level',
                    label: 'Default service level',
                    help: 'The target for new categories.',
                    type: 'number',
                    unit: '%',
                    min: 50,
                    max: 99.9,
                    choices: [],
                    value: 95,
                    default: 95,
                    is_default: true,
                },
            ],
        },
        {
            name: 'Schedule',
            settings: [
                {
                    key: 'forecast_schedule',
                    label: 'Refresh forecasts automatically',
                    help: 'Retrain on a schedule.',
                    type: 'boolean',
                    unit: null,
                    min: null,
                    max: null,
                    choices: [],
                    value: true,
                    default: true,
                    is_default: true,
                },
                {
                    key: 'forecast_weekly_day',
                    label: 'Weekly forecast runs on',
                    help: 'The day.',
                    type: 'choice',
                    unit: null,
                    min: null,
                    max: null,
                    choices: [
                        { value: 1, label: 'Monday' },
                        { value: 2, label: 'Tuesday' },
                        { value: 0, label: 'Sunday' },
                    ],
                    value: 1,
                    default: 1,
                    is_default: true,
                },
                {
                    key: 'digest_time',
                    label: 'Digest emails are sent at',
                    help: 'After the refresh.',
                    type: 'time',
                    unit: null,
                    min: null,
                    max: null,
                    choices: [],
                    value: '07:00',
                    default: '07:00',
                    is_default: true,
                },
            ],
        },
    ];
}

export function makeAuditEntry(changes: Partial<AuditEntry> = {}): AuditEntry {
    return {
        id: 1,
        area: 'settings',
        area_label: 'System settings',
        description: 'Changed the system settings',
        who: 'Olive Owner',
        subject: null,
        details: [{ label: 'Review period', from: '7', to: '14' }],
        created_at: '2026-10-05T08:00:00+08:00',
        ...changes,
    };
}
