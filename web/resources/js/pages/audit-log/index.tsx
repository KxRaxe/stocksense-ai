import { Head } from '@inertiajs/react';
import AuditFilters from '@/components/audit/audit-filters';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { index } from '@/routes/audit-log';
import type { AuditEntry, AuditLogProps } from '@/types';

function Details({ details }: { details: AuditEntry['details'] }) {
    if (details.length === 0) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <details className="text-sm" data-test="audit-details">
            <summary className="cursor-pointer text-muted-foreground">
                {details.length} {details.length === 1 ? 'detail' : 'details'}
            </summary>
            <ul className="mt-1 space-y-0.5">
                {details.map((detail, position) => (
                    <li key={`${detail.label}-${position}`}>
                        <span className="font-medium">{detail.label}:</span>{' '}
                        {detail.from !== null && (
                            <>
                                <span className="text-muted-foreground line-through">
                                    {detail.from}
                                </span>{' '}
                                to{' '}
                            </>
                        )}
                        {detail.to ?? '-'}
                    </li>
                ))}
            </ul>
        </details>
    );
}

export default function AuditLog({
    entries,
    filters,
    areas,
    users,
}: AuditLogProps) {
    return (
        <>
            <Head title="Audit log" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Audit log"
                    description="Who did what, and when, newest first. Only the Owner can see this, and nothing here can be changed or deleted."
                />

                <AuditFilters
                    url={index().url}
                    filters={filters}
                    areas={areas}
                    users={users}
                />

                <div className="overflow-x-auto rounded-lg border">
                    <Table data-test="audit-table">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">When</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>Area</TableHead>
                                <TableHead>What happened</TableHead>
                                <TableHead className="pr-4">Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="p-8 text-center text-muted-foreground"
                                        data-test="no-entries"
                                    >
                                        Nothing matches.
                                    </TableCell>
                                </TableRow>
                            )}
                            {entries.data.map((entry) => (
                                <TableRow
                                    key={entry.id}
                                    data-test={`audit-entry-${entry.id}`}
                                >
                                    <TableCell className="pl-4 whitespace-nowrap">
                                        {formatDateTime(entry.created_at)}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {entry.who ?? (
                                            <span className="text-muted-foreground">
                                                The system
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {entry.area_label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <div>{entry.description}</div>
                                        {entry.subject && (
                                            <div className="text-xs text-muted-foreground">
                                                {entry.subject}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="pr-4">
                                        <Details details={entry.details} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={entries} />
            </div>
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [{ title: 'Audit log', href: index() }],
};
