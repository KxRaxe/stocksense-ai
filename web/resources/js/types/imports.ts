import type { Paginated } from '@/types/catalog';

export type ImportStatus =
    | 'preview'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'undone';

/** The addresses of an import's actions, as the server built them. */
export type ImportLinks = {
    show: string;
    update: string;
    confirm: string;
    cancel: string;
    undo: string;
    errors: string;
    index: string;
};

/** A choice the person makes about an import, to be drawn as a form control. */
export type ImportOption = {
    name: string;
    label: string;
    kind: 'select' | 'radio' | 'checkbox';
    /** Asked for when the file is uploaded (all are shown in the preview). */
    upload: boolean;
    choices: { value: string; label: string; help?: string }[];
    help?: string;
};

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
    rows_updated: number;
    rows_failed: number;
    errors: { row: number; messages: string[] }[];
    has_error_report: boolean;
    error_message: string | null;
    headers: string[];
    /** Field => 0-based column number, or null if not chosen. */
    columns: Record<string, number | null>;
    /** Each choice's current value as text ("1"/"0" for yes/no). */
    options: Record<string, string>;
    user: string | null;
    created_at: string | null;
    finished_at: string | null;
    links: ImportLinks;
    // Only sent with the page for a single import, not the list of imports.
    result_figures?: { label: string; value: number }[];
    /** What undoing will do, in plain words. */
    undo_description?: string;
    /** The page showing what the import brought in, if there is anything to show. */
    results?: { url: string; label: string } | null;
};

export type ImportField = { key: string; label: string; required: boolean };

export type ImportPreviewData = {
    importable_rows: number;
    /** The text of the button that starts the import. */
    import_label: string;
    figures: {
        label: string;
        value: number;
        tone: 'good' | 'neutral' | 'warn';
    }[];
    notes: string[];
    sample: {
        row: number;
        cells: (string | number | null)[];
        status: 'ok' | 'skip' | 'error';
        label: string;
        messages: string[];
    }[];
    problems: { row: number; messages: string[] }[];
    invalid_rows: number;
};

export type ImportBatchPage = Paginated<ImportBatch>;
