/** A line of sales history. */
export type SaleRow = {
    id: number;
    /** YYYY-MM-DD */
    sold_on: string;
    product: { id: number; sku: string; name: string; unit: string };
    quantity: number;
    unit_price: number;
    total: number;
    source: 'manual' | 'import';
    source_label: string;
    import_batch_id: number | null;
    user: string | null;
};

export type SalesTotals = {
    lines: number;
    units: number;
    revenue: number;
};

export type SalesFilters = {
    search: string;
    from: string | null;
    to: string | null;
    source: '' | 'manual' | 'import';
    import: number | null;
};

/** A product offered when entering sales by hand. */
export type SaleProductOption = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    unit_price: number;
    on_hand: number;
};
