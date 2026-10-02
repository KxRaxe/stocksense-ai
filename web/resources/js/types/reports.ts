import type { Option, Paginated } from '@/types/catalog';
import type { ForecastGranularity } from '@/types/forecasts';

/** Mirrors App\Services\Reporting\Reports\ReportColumn. */
export type ColumnType =
    | 'text'
    | 'integer'
    | 'decimal'
    | 'money'
    | 'percent'
    | 'date'
    | 'datetime';

/** Which filters a report understands. */
export type ReportFilterName = 'date' | 'category' | 'granularity';

/** A report as listed. */
export type ReportInfo = {
    key: string;
    title: string;
    description: string;
    filters: ReportFilterName[];
};

export type ReportColumnDef = { key: string; label: string; type: ColumnType };

export type ReportCell = string | number | null;

export type ReportRow = Record<string, ReportCell>;

export type ReportSummaryItem = {
    label: string;
    value: ReportCell;
    type: ColumnType;
};

export type ReportFiltersState = {
    from: string;
    to: string;
    category: number | null;
    granularity: ForecastGranularity;
};

export type ReportExportLink = {
    format: 'xlsx' | 'pdf';
    label: string;
    url: string;
};

export type ReportShowProps = {
    report: ReportInfo;
    /** The reports this person can switch between. */
    reports: { key: string; title: string }[];
    filters: ReportFiltersState;
    /** What was wrong with the filters in the address, by filter. */
    filter_errors: Record<string, string>;
    /** What the report is narrowed to, in words. */
    subtitle: string;
    columns: ReportColumnDef[];
    rows: Paginated<ReportRow>;
    summary: ReportSummaryItem[];
    totals: ReportRow | null;
    notes: string[];
    categories: Option[];
    exports: ReportExportLink[];
};

export type ReportsIndexProps = { reports: ReportInfo[] };
