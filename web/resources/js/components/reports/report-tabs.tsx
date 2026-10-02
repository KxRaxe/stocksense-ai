import { Link } from '@inertiajs/react';
import { show } from '@/routes/reports';

type Props = {
    reports: { key: string; title: string }[];
    current: string;
};

/** Switch between the reports this person can open. */
export default function ReportTabs({ reports, current }: Props) {
    if (reports.length < 2) {
        return null;
    }

    return (
        <nav
            className="inline-flex flex-wrap rounded-lg bg-muted p-1 text-sm"
            aria-label="Reports"
            role="tablist"
        >
            {reports.map((report) => {
                const selected = report.key === current;

                return (
                    <Link
                        key={report.key}
                        href={show(report.key)}
                        role="tab"
                        aria-selected={selected}
                        className={`rounded-md px-3 py-1 font-medium transition-colors ${
                            selected
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                        data-test={`report-tab-${report.key}`}
                    >
                        {report.title}
                    </Link>
                );
            })}
        </nav>
    );
}
