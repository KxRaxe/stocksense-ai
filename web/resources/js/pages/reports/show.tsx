import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import ReportFilters from '@/components/reports/report-filters';
import ReportTable from '@/components/reports/report-table';
import LinkTabs from '@/components/link-tabs';
import { Button } from '@/components/ui/button';
import { formatCell } from '@/lib/reports';
import { index, show } from '@/routes/reports';
import type { ReportShowProps } from '@/types';

export default function ReportShow({
    report,
    reports,
    filters,
    filter_errors,
    subtitle,
    columns,
    rows,
    summary,
    totals,
    notes,
    categories,
    exports,
}: ReportShowProps) {
    return (
        <>
            <Head title={report.title} />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={report.title}
                        description={report.description}
                    />
                    <div className="flex flex-wrap gap-2">
                        {exports.map((link) => (
                            <Button key={link.format} variant="outline" asChild>
                                <a
                                    href={link.url}
                                    download
                                    data-test={`export-${link.format}`}
                                >
                                    <Download />
                                    {link.label}
                                </a>
                            </Button>
                        ))}
                    </div>
                </div>

                {reports.length > 1 && (
                    <LinkTabs
                        label="Reports"
                        testPrefix="report-tab"
                        current={report.key}
                        tabs={reports.map((item) => ({
                            key: item.key,
                            label: item.title,
                            href: show(item.key).url,
                        }))}
                    />
                )}

                <ReportFilters
                    // A new key when the report or its filters change, so the form shows them.
                    key={`${report.key}-${JSON.stringify(filters)}`}
                    reportKey={report.key}
                    supports={report.filters}
                    filters={filters}
                    categories={categories}
                    errors={filter_errors}
                />

                {subtitle && (
                    <p
                        className="text-sm text-muted-foreground"
                        data-test="report-subtitle"
                    >
                        {subtitle}
                    </p>
                )}

                {summary.length > 0 && (
                    <div
                        className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        data-test="report-summary"
                    >
                        {summary.map((item) => (
                            <div
                                key={item.label}
                                className="rounded-lg border p-3"
                            >
                                <p className="text-xs text-muted-foreground">
                                    {item.label}
                                </p>
                                <p className="mt-1 text-lg font-semibold">
                                    {formatCell(item.value, item.type)}
                                </p>
                            </div>
                        ))}
                    </div>
                )}

                <ReportTable
                    columns={columns}
                    rows={rows.data}
                    totals={totals}
                    showTotals={rows.current_page === rows.last_page}
                />

                <Pagination paginator={rows} />

                {notes.length > 0 && (
                    <ul
                        className="list-disc space-y-1 pl-5 text-sm text-muted-foreground"
                        data-test="report-notes"
                    >
                        {notes.map((note) => (
                            <li key={note}>{note}</li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

ReportShow.layout = {
    breadcrumbs: [{ title: 'Reports', href: index() }],
};
