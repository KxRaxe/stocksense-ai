import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import ImportPreview from '@/components/imports/import-preview';
import ImportResult from '@/components/imports/import-result';
import ImportStatusBadge from '@/components/imports/import-status-badge';
import { formatNumber } from '@/lib/format';
import type {
    ImportBatch,
    ImportField,
    ImportOption,
    ImportPreviewData,
} from '@/types';

export type ShowImportProps = {
    batch: ImportBatch;
    // Only sent while the file is being checked.
    preview?: ImportPreviewData;
    fields?: ImportField[];
    options?: ImportOption[];
};

function Progress({ batch }: { batch: ImportBatch }) {
    const percent =
        batch.rows_total === 0
            ? 0
            : Math.round((batch.rows_processed / batch.rows_total) * 100);

    return (
        <div
            className="max-w-xl space-y-3"
            role="status"
            data-test="import-progress"
        >
            <div className="flex justify-between text-sm">
                <span>
                    {batch.status === 'queued'
                        ? 'Waiting to start...'
                        : `Imported ${formatNumber(batch.rows_processed)} of ${formatNumber(batch.rows_total)} rows`}
                </span>
                <span className="text-muted-foreground">{percent}%</span>
            </div>
            <div
                className="h-2 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    className="h-full bg-primary transition-all"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <p className="text-sm text-muted-foreground">
                You can leave this page. The import keeps running, and this page
                updates itself.
            </p>
        </div>
    );
}

/**
 * One import, whatever it imports: the preview while the file is being
 * checked, progress while it runs, the result afterwards.
 */
export default function ShowImport({
    batch,
    preview,
    fields,
    options,
}: ShowImportProps) {
    // Refresh just the batch every couple of seconds while it is running.
    const { start, stop } = usePoll(
        2000,
        { only: ['batch'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (batch.is_active) {
            start();
        } else {
            stop();
        }

        return stop;
        // start and stop come from the poll hook; only the running state matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [batch.is_active]);

    return (
        <>
            <Head title={batch.filename} />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={batch.filename}
                        description={
                            batch.status === 'preview'
                                ? `${formatNumber(batch.rows_total)} rows. Check the columns, then import. Nothing is saved until you do.`
                                : `Import #${batch.id}${batch.user ? ` by ${batch.user}` : ''}`
                        }
                    />
                    <ImportStatusBadge
                        status={batch.status}
                        label={batch.status_label}
                    />
                </div>

                {batch.status === 'preview' && preview && fields && options ? (
                    <ImportPreview
                        batch={batch}
                        preview={preview}
                        fields={fields}
                        options={options}
                    />
                ) : batch.is_active ? (
                    <Progress batch={batch} />
                ) : (
                    <ImportResult batch={batch} />
                )}
            </div>
        </>
    );
}
