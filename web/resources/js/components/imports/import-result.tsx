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
                    This import was undone.
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {(batch.result_figures ?? []).map((figure) => (
                    <Figure key={figure.label} {...figure} />
                ))}
            </div>

            <div className="flex flex-wrap items-center gap-3">
                {batch.results && (
                    <Button variant="outline" asChild>
                        <Link
                            href={batch.results.url}
                            data-test="view-import-results"
                        >
                            {batch.results.label}
                        </Link>
                    </Button>
                )}
                {batch.has_error_report && (
                    <Button variant="outline" asChild>
                        <a
                            href={batch.links.errors}
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
                description={batch.undo_description ?? ''}
                confirmLabel="Undo import"
                destructive
                testId="confirm-undo-import"
                onConfirm={() =>
                    router.post(
                        batch.links.undo,
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
