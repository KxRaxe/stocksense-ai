import { Link, router } from '@inertiajs/react';
import { Download, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { firstError } from '@/lib/errors';
import { formatNumber } from '@/lib/format';
import { index as salesIndex } from '@/routes/sales';
import { errors as errorReport, undo } from '@/routes/sales/imports';
import type { ImportBatch } from '@/types';

function Figure({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight">
                {formatNumber(value)}
            </div>
        </div>
    );
}

/**
 * What happened to a file that has been imported (or has failed or been
 * undone): the counts, the rows that failed, and what can still be done.
 */
export default function ImportResult({ batch }: { batch: ImportBatch }) {
    const [confirmingUndo, setConfirmingUndo] = useState(false);

    return (
        <div className="space-y-8">
            {batch.status === 'failed' && (
                <div
                    className="rounded-lg border border-destructive/50 bg-destructive/10 p-4 text-sm"
                    role="alert"
                >
                    {batch.error_message}
                </div>
            )}

            {batch.status === 'undone' && (
                <div className="rounded-lg border p-4 text-sm" role="status">
                    This import was undone. Its sales were removed and any stock
                    it took was put back.
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Figure label="Rows in the file" value={batch.rows_total} />
                <Figure label="Imported" value={batch.rows_ok} />
                <Figure
                    label="Already there, skipped"
                    value={batch.rows_duplicate}
                />
                <Figure label="Failed" value={batch.rows_failed} />
            </div>

            <div className="flex flex-wrap items-center gap-3">
                {batch.rows_ok > 0 && batch.status !== 'undone' && (
                    <Button variant="outline" asChild>
                        <Link
                            href={salesIndex({ query: { import: batch.id } })}
                            data-test="view-imported-sales"
                        >
                            View the imported sales
                        </Link>
                    </Button>
                )}
                {batch.has_error_report && (
                    <Button variant="outline" asChild>
                        <a
                            href={errorReport.url(batch.id)}
                            download
                            data-test="download-error-report"
                        >
                            <Download />
                            Download the error report
                        </a>
                    </Button>
                )}
                {batch.can_undo && (
                    <Button
                        variant="outline"
                        onClick={() => setConfirmingUndo(true)}
                        data-test="undo-import-button"
                    >
                        <Undo2 />
                        Undo this import
                    </Button>
                )}
            </div>

            {batch.errors.length > 0 && (
                <div className="space-y-2">
                    <p className="font-medium">
                        Rows that failed
                        {batch.rows_failed > batch.errors.length &&
                            ` (first ${batch.errors.length} of ${formatNumber(batch.rows_failed)}; the report has them all)`}
                    </p>
                    <div className="rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-4">Row</TableHead>
                                    <TableHead className="pr-4">
                                        Problem
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {batch.errors.map((error) => (
                                    <TableRow key={error.row}>
                                        <TableCell className="pl-4">
                                            {error.row}
                                        </TableCell>
                                        <TableCell className="pr-4 whitespace-normal">
                                            {error.messages.join(' ')}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            )}

            <ConfirmDialog
                open={confirmingUndo}
                onOpenChange={setConfirmingUndo}
                title="Undo this import?"
                description={`This removes the ${formatNumber(batch.rows_ok)} sales it brought in${batch.adjust_stock ? ' and puts their stock back' : ''}. The change is recorded. You can upload the file again afterwards.`}
                confirmLabel="Undo import"
                destructive
                testId="confirm-undo-import"
                onConfirm={() =>
                    router.post(
                        undo.url(batch.id),
                        {},
                        {
                            onFinish: () => setConfirmingUndo(false),
                            onError: (errors) =>
                                toast.error(firstError(errors)),
                        },
                    )
                }
            />
        </div>
    );
}
