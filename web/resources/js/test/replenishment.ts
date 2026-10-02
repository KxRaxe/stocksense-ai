import type {
    NotificationItem,
    RecommendationFilters,
    RecommendationRow,
} from '@/types';

/** A pending, critical recommendation for nails; change what a test cares about. */
export function makeRecommendation(
    changes: Partial<RecommendationRow> = {},
): RecommendationRow {
    return {
        id: 5,
        product: {
            id: 13,
            sku: 'HW-1',
            name: 'Nails',
            category: 'Hardware',
            unit: 'pc',
            moq: 1,
            pack_size: 1,
        },
        risk: 'critical',
        risk_label: 'Critical',
        status: 'pending',
        status_label: 'Pending',
        on_hand: 10,
        on_order: 0,
        lead_time_days: 7,
        lead_time_demand: 70,
        safety_stock: 0,
        reorder_point: 70,
        order_up_to: 140,
        days_of_cover: 1.4,
        recommended_qty: 130,
        // Long past, so it always reads as "Order now".
        order_by_date: '2000-01-01',
        explanation:
            'Order 130 now: 10 on hand is below the 70 expected over the 7-day lead time.',
        final_qty: null,
        decided_by: null,
        decided_at: null,
        note: null,
        snoozed_until: null,
        cancelled_at: null,
        is_pending: true,
        is_ordered: false,
        ...changes,
    };
}

export const noFilters: RecommendationFilters = {
    view: 'todo',
    risk: '',
    category: null,
    search: '',
};

export function makeNotification(
    changes: Partial<NotificationItem> = {},
): NotificationItem {
    return {
        id: 'abc-1',
        type: 'critical_stock',
        title: 'Critical stock: 2 products may run out',
        message: 'Nails and Cement may run out.',
        url: '/recommendations?risk=critical',
        read: false,
        created_at: new Date().toISOString(),
        ...changes,
    };
}
