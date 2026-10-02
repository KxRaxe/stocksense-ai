import { Head, Link } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import ImportStatusBadge from '@/components/sales/import-status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatNumber } from '@/lib/format';
import { index as salesIndex } from '@/routes/sales';
import { create, index, show } from '@/routes/sales/imports';
import type { ImportBatchPage } from '@/types';

export default function ImportsIndex({
    batches,
}: {
    batches: ImportBatchPage;
}) {
    return (
        <>
            <Head title="Imports" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Sales imports"
                        description="Files of sales you have uploaded. Open one to see what happened to each row, or to undo it."
                    />
                    <Button asChild data-test="upload-file-button">
                        <Link href={create()}>
                            <Upload />
                            Upload a file
                        </Link>
                    </Button>
                </div>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">File</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Rows
                                </TableHead>
                                <TableHead className="text-right">
                                    Imported
                                </TableHead>
                                <TableHead className="text-right">
                                    Already there
                                </TableHead>
                                <TableHead className="text-right">
                                    Failed
                                </TableHead>
                                <TableHead>By</TableHead>
                                <TableHead className="pr-4">When</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {batches.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        Nothing has been imported yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {batches.data.map((batch) => (
                                <TableRow
                                    key={batch.id}
                                    data-test={`import-row-${batch.id}`}
                                >
                                    <TableCell className="pl-4 font-medium">
                                        <Link
                                            href={show(batch.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {batch.filename}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        <ImportStatusBadge
                                            status={batch.status}
                                            label={batch.status_label}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(batch.rows_total)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(batch.rows_ok)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(batch.rows_duplicate)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(batch.rows_failed)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {batch.user ?? '-'}
                                    </TableCell>
                                    <TableCell className="pr-4 text-muted-foreground">
                                        {batch.created_at
                                            ? formatDateTime(batch.created_at)
                                            : '-'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={batches} />
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [
        { title: 'Sales', href: salesIndex() },
        { title: 'Imports', href: index() },
    ],
};
