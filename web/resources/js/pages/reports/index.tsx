import { Head, Link } from '@inertiajs/react';
import { BarChart3, ClipboardList, Package, TrendingUp } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import Heading from '@/components/heading';
import { index, show } from '@/routes/reports';
import type { ReportsIndexProps } from '@/types';

const icons: Record<string, LucideIcon> = {
    sales: BarChart3,
    inventory: Package,
    'forecast-accuracy': TrendingUp,
    replenishment: ClipboardList,
};

const filterWords = {
    date: 'period',
    category: 'category',
    granularity: 'weekly or monthly',
};

export default function ReportsIndex({ reports }: ReportsIndexProps) {
    return (
        <>
            <Head title="Reports" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Reports"
                    description="Open a report on screen, or download it as an Excel file or a PDF."
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    {reports.map((report) => {
                        const Icon = icons[report.key] ?? BarChart3;

                        return (
                            <Link
                                key={report.key}
                                href={show(report.key)}
                                className="space-y-2 rounded-lg border p-5 transition-colors hover:bg-muted/50"
                                data-test={`report-${report.key}`}
                            >
                                <div className="flex items-center gap-2">
                                    <Icon className="size-5 text-muted-foreground" />
                                    <h3 className="font-medium">
                                        {report.title}
                                    </h3>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {report.description}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Filter by{' '}
                                    {report.filters
                                        .map((name) => filterWords[name])
                                        .join(', ')}
                                    .
                                    {!report.filters.includes('date') &&
                                        ' Always as of now.'}
                                </p>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: index() }],
};
