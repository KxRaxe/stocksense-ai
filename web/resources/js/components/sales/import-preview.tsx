import { Form, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Copy } from 'lucide-react';
import { toast } from 'sonner';
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
import { cancel, confirm, update } from '@/routes/sales/imports';
import type { ImportBatch, ImportField, ImportPreviewData } from '@/types';

type Props = {
    batch: ImportBatch;
    preview: ImportPreviewData;
    fields: ImportField[];
    dateFormats: { value: string; label: string }[];
};

function Figure({
    icon,
    label,
    value,
    tone,
}: {
    icon: React.ReactNode;
    label: string;
    value: number;
    tone: string;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className={`flex items-center gap-2 text-sm ${tone}`}>
                {icon}
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
 * see what would happen to every row, then confirm. Nothing is saved until
 * the person confirms.
 */
export default function ImportPreview({
    batch,
    preview,
    fields,
    dateFormats,
}: Props) {
    const missing = fields.filter(
        (field) => field.required && batch.columns[field.key] === null,
    );

    // The form restarts from the saved settings after each update.
    const formKey = JSON.stringify([
        batch.columns,
        batch.date_format,
        batch.adjust_stock,
    ]);

    return (
        <div className="space-y-8">
            <div className="grid gap-4 sm:grid-cols-3">
                <Figure
                    icon={<CheckCircle2 className="size-4" />}
                    label="Will be imported"
                    value={preview.importable_rows}
                    tone="text-emerald-600 dark:text-emerald-400"
                />
                <Figure
                    icon={<Copy className="size-4" />}
                    label="Already imported, skipped"
                    value={preview.duplicate_rows}
                    tone="text-muted-foreground"
                />
                <Figure
                    icon={<AlertTriangle className="size-4" />}
                    label="Have a problem, skipped"
                    value={preview.invalid_rows}
                    tone="text-amber-600 dark:text-amber-400"
                />
            </div>

            <Form
                key={formKey}
                {...update.form(batch.id)}
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

                                <div className="grid gap-2">
                                    <Label htmlFor="date_format">
                                        How are dates written?
                                    </Label>
                                    <NativeSelect
                                        id="date_format"
                                        name="date_format"
                                        defaultValue={batch.date_format}
                                    >
                                        {dateFormats.map((format) => (
                                            <option
                                                key={format.value}
                                                value={format.value}
                                            >
                                                {format.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={errors.date_format} />
                                </div>
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    Should these sales change stock levels?
                                </legend>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="adjust_stock"
                                        value="0"
                                        defaultChecked={!batch.adjust_stock}
                                    />
                                    No, this is past sales history
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="adjust_stock"
                                        value="1"
                                        defaultChecked={batch.adjust_stock}
                                    />
                                    Yes, take them off the shelf
                                </label>
                            </fieldset>

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
                                        confirm.url(batch.id),
                                        {},
                                        {
                                            onError: (errors) =>
                                                toast.error(firstError(errors)),
                                        },
                                    )
                                }
                                data-test="start-import-button"
                            >
                                Import {formatNumber(preview.importable_rows)}{' '}
                                {preview.importable_rows === 1 ? 'row' : 'rows'}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() =>
                                    router.delete(cancel.url(batch.id), {
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
                                            <Badge variant="outline">OK</Badge>
                                        )}
                                        {row.status === 'duplicate' && (
                                            <Badge variant="secondary">
                                                Already imported
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
