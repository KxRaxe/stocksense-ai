import type { Paginated } from '@/types/catalog';

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

export type ImportStatus =
    | 'preview'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'undone';

export type ImportBatch = {
    id: number;
    filename: string;
    status: ImportStatus;
    status_label: string;
    /** True while the file is queued or being imported. */
    is_active: boolean;
    can_undo: boolean;
    rows_total: number;
    rows_processed: number;
    rows_ok: number;
    rows_duplicate: number;
    rows_failed: number;
    errors: { row: number; messages: string[] }[];
    has_error_report: boolean;
    error_message: string | null;
    adjust_stock: boolean;
    headers: string[];
    /** Field => 0-based column number, or null if not chosen. */
    columns: Record<string, number | null>;
    date_format: string;
    user: string | null;
    created_at: string | null;
    finished_at: string | null;
};

export type ImportField = { key: string; label: string; required: boolean };

export type ImportPreviewData = {
    total_rows: number;
    importable_rows: number;
    duplicate_rows: number;
    invalid_rows: number;
    sample: {
        row: number;
        cells: (string | number | null)[];
        status: 'ok' | 'duplicate' | 'error';
        messages: string[];
    }[];
    problems: { row: number; messages: string[] }[];
};

export type ImportBatchPage = Paginated<ImportBatch>;
