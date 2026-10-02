import { Form, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Copy, Info } from 'lucide-react';
import { toast } from 'sonner';
import ImportOptions from '@/components/imports/import-options';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
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
import type {
    ImportBatch,
    ImportField,
    ImportOption,
    ImportPreviewData,
} from '@/types';

type Props = {
    batch: ImportBatch;
    preview: ImportPreviewData;
    fields: ImportField[];
    options: ImportOption[];
};

type Tone = ImportPreviewData['figures'][number]['tone'];

const tones: Record<Tone, { icon: React.ReactNode; text: string }> = {
    good: {
        icon: <CheckCircle2 className="size-4" />,
        text: 'text-emerald-600 dark:text-emerald-400',
    },
    neutral: {
        icon: <Copy className="size-4" />,
        text: 'text-muted-foreground',
    },
    warn: {
        icon: <AlertTriangle className="size-4" />,
        text: 'text-amber-600 dark:text-amber-400',
    },
};

function Figure({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: Tone;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div
                className={`flex items-center gap-2 text-sm ${tones[tone].text}`}
            >
                {tones[tone].icon}
                {label}
            </div>
            <div className="mt-1 text-2xl font-semibold tracking-tight">
                {formatNumber(value)}
            </div>
        </div>
    );
}

/**
 * Step two of an import: match the file's columns to what the system needs,
 * make the choices for this kind of file, see what would happen to every row,
 * then confirm. Nothing is saved until the person confirms.
 */
export default function ImportPreview({
    batch,
    preview,
    fields,
    options,
}: Props) {
    const missing = fields.filter(
        (field) => field.required && batch.columns[field.key] === null,
    );

    // The form restarts from the saved settings after each update.
    const formKey = JSON.stringify([batch.columns, batch.options]);

    return (
        <div className="space-y-8">
            <div className="grid gap-4 sm:grid-cols-3">
                {preview.figures.map((figure) => (
                    <Figure key={figure.label} {...figure} />
                ))}
            </div>

            {preview.notes.length > 0 && (
                <ul
                    className="space-y-2 rounded-lg border bg-muted/40 p-4 text-sm"
                    data-test="preview-notes"
                >
                    {preview.notes.map((note) => (
                        <li key={note} className="flex items-start gap-2">
                            <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            {note}
                        </li>
                    ))}
                </ul>
            )}

            <Form
                key={formKey}
                action={batch.links.update}
                method="put"
                options={{ preserveScroll: true }}
                className="space-y-6"
            >
                {({ errors, isDirty, processing }) => (
                    <>
                        <div className="space-y-4 rounded-lg border p-4">
                            <p className="font-medium">
                                Which column is which?
                            </p>

                            <div className="grid gap-4 sm:grid-cols-2">
                                {fields.map((field) => (
                                    <div key={field.key} className="grid gap-2">
                                        <Label htmlFor={`column-${field.key}`}>
                                            {field.label}
                                            {field.required && (
                                                <span className="text-destructive">
                                                    {' '}
                                                    *
                                                </span>
                                            )}
                                        </Label>
                                        <NativeSelect
                                            id={`column-${field.key}`}
                                            name={`columns[${field.key}]`}
                                            defaultValue={
                                                batch.columns[field.key] ?? ''
                                            }
                                        >
                                            <option value="">
                                                {field.required
                                                    ? 'Choose a column'
                                                    : 'Not in my file'}
                                            </option>
                                            {batch.headers.map(
                                                (header, position) => (
                                                    <option
                                                        key={position}
                                                        value={position}
                                                    >
                                                        {header}
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                        <InputError
                                            message={
                                                (
                                                    errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`columns.${field.key}`]
                                            }
                                        />
                                    </div>
                                ))}
                            </div>

                            <div className="grid gap-4 border-t pt-4 sm:grid-cols-2">
                                <ImportOptions
                                    options={options}
                                    values={batch.options}
                                    errors={errors}
                                    compact
                                />
                            </div>

                            {missing.length > 0 && (
                                <p className="text-sm text-amber-600 dark:text-amber-400">
                                    Choose a column for{' '}
                                    {missing
                                        .map((field) => field.label)
                                        .join(', ')}{' '}
                                    before importing.
                                </p>
                            )}
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing || !isDirty}
                                data-test="update-preview-button"
                            >
                                Update preview
                            </Button>
                            <Button
                                type="button"
                                disabled={
                                    isDirty ||
                                    processing ||
                                    missing.length > 0 ||
                                    preview.importable_rows === 0
                                }
                                onClick={() =>
                                    router.post(
                                        batch.links.confirm,
                                        {},
                                        {
                                            onError: (errors) =>
                                                toast.error(firstError(errors)),
                                        },
                                    )
                                }
                                data-test="start-import-button"
                            >
                                {preview.import_label}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(batch.links.cancel, {
                                        onError: (errors) =>
                                            toast.error(firstError(errors)),
                                    })
                                }
                                data-test="cancel-import-button"
                            >
                                Discard this file
                            </Button>
                            {isDirty && (
                                <span className="text-sm text-muted-foreground">
                                    Update the preview to see the effect of your
                                    changes.
                                </span>
                            )}
                        </div>
                    </>
                )}
            </Form>

            {preview.problems.length > 0 && (
                <div className="space-y-2">
                    <p className="font-medium">
                        Problems found
                        {preview.invalid_rows > preview.problems.length &&
                            ` (first ${preview.problems.length} of ${formatNumber(preview.invalid_rows)})`}
                    </p>
                    <ul
                        className="space-y-1 rounded-lg border p-3 text-sm"
                        data-test="preview-problems"
                    >
                        {preview.problems.map((problem) => (
                            <li key={problem.row}>
                                <span className="font-medium">
                                    Row {problem.row}:
                                </span>{' '}
                                {problem.messages.join(' ')}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="space-y-2">
                <p className="font-medium">First rows of your file</p>
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">Row</TableHead>
                                {batch.headers.map((header, position) => (
                                    <TableHead key={position}>
                                        {header}
                                    </TableHead>
                                ))}
                                <TableHead className="pr-4">Result</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {preview.sample.map((row) => (
                                <TableRow
                                    key={row.row}
                                    data-test={`sample-row-${row.row}`}
                                >
                                    <TableCell className="pl-4 text-muted-foreground">
                                        {row.row}
                                    </TableCell>
                                    {batch.headers.map((_, position) => (
                                        <TableCell key={position}>
                                            {row.cells[position] ?? ''}
                                        </TableCell>
                                    ))}
                                    <TableCell className="pr-4">
                                        {row.status === 'ok' && (
                                            <Badge variant="outline">
                                                {row.label}
                                            </Badge>
                                        )}
                                        {row.status === 'skip' && (
                                            <Badge variant="secondary">
                                                {row.label}
                                            </Badge>
                                        )}
                                        {row.status === 'error' && (
                                            <span className="text-sm whitespace-normal text-destructive">
                                                {row.messages.join(' ')}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </div>
    );
}
