/** Laravel's paginator, as Inertia delivers it. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Option = { id: number; name: string };

export type StockStatus = 'out_of_stock' | 'low' | 'ok';

export type CategoryRow = {
    id: number;
    name: string;
    description: string | null;
    /** Target service level as a percentage, e.g. 95. */
    service_level: number;
    products_count: number;
};

/** A product as listed in the catalog. */
export type ProductRow = {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    unit_cost: number;
    unit_price: number;
    is_active: boolean;
};

/** A product with every editable field. */
export type ProductDetails = ProductRow & {
    category_id: number;
    lead_time_days: number;
    moq: number;
    pack_size: number;
    reorder_point_override: number | null;
    safety_stock_override: number | null;
};

/** A product as listed in the stock overview. */
export type StockRow = {
    id: number;
    sku: string;
    name: string;
    category: string;
    unit: string;
    on_hand: number;
    on_order: number;
    reorder_point: number | null;
    lead_time_days: number;
    status: StockStatus;
};

export type StockMovementRow = {
    id: number;
    type: 'initial' | 'restock' | 'sale' | 'adjustment';
    type_label: string;
    /** Signed: positive added stock, negative removed it. */
    quantity: number;
    occurred_at: string;
    note: string | null;
    user: string | null;
};
