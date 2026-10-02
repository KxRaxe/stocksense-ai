import { Form, Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as salesIndex } from '@/routes/sales';
import { create, index, store, template } from '@/routes/sales/imports';

type Props = { maxMegabytes: number; maxRows: number };

export default function UploadSalesFile({ maxMegabytes, maxRows }: Props) {
    return (
        <>
            <Head title="Upload a sales file" />

            <div className="space-y-8 p-4">
                <Heading
                    title="Upload a sales file"
                    description="A CSV or Excel (.xlsx) file with one row per sale. You will check the columns before anything is imported."
                />

                <div className="max-w-2xl space-y-3 rounded-lg border p-4 text-sm">
                    <p className="font-medium">What the file needs</p>
                    <ul className="list-disc space-y-1 pl-5 text-muted-foreground">
                        <li>
                            A first row of column names, then one sale per row.
                        </li>
                        <li>
                            <strong className="text-foreground">Date</strong>,{' '}
                            <strong className="text-foreground">SKU</strong> and{' '}
                            <strong className="text-foreground">
                                quantity
                            </strong>{' '}
                            are required. Unit price is optional; if it is empty
                            the product's current price is used.
                        </li>
                        <li>
                            Up to {maxMegabytes} MB and{' '}
                            {maxRows.toLocaleString()} rows per file.
                        </li>
                        <li>
                            Rows that were imported before are skipped, so it is
                            safe to upload an overlapping file.
                        </li>
                    </ul>
                    <Button variant="outline" size="sm" asChild>
                        <a href={template().url} download>
                            <Download />
                            Download an example file
                        </a>
                    </Button>
                </div>

                <Form
                    {...store.form()}
                    className="max-w-2xl space-y-6"
                    encType="multipart/form-data"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="file">File</Label>
                                <Input
                                    id="file"
                                    name="file"
                                    type="file"
                                    accept=".csv,.txt,.xlsx"
                                    required
                                />
                                <InputError message={errors.file} />
                            </div>

                            <fieldset className="grid gap-3">
                                <legend className="mb-1 text-sm font-medium">
                                    Should these sales change stock levels?
                                </legend>
                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="radio"
                                        name="adjust_stock"
                                        value="0"
                                        defaultChecked
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="font-medium">
                                            No, this is past sales history
                                        </span>
                                        <span className="block text-muted-foreground">
                                            Your current stock count already
                                            reflects these sales. Use this for
                                            old data, so the forecasts have
                                            history to learn from.
                                        </span>
                                    </span>
                                </label>
                                <label className="flex items-start gap-3 text-sm">
                                    <input
                                        type="radio"
                                        name="adjust_stock"
                                        value="1"
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="font-medium">
                                            Yes, take them off the shelf
                                        </span>
                                        <span className="block text-muted-foreground">
                                            Use this for sales that have not
                                            been taken off your stock yet, such
                                            as yesterday's export from your cash
                                            register.
                                        </span>
                                    </span>
                                </label>
                                <InputError message={errors.adjust_stock} />
                            </fieldset>

                            <div className="flex items-center gap-3">
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="upload-button"
                                >
                                    {processing && <Spinner />}
                                    Upload and check
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={salesIndex()}>Cancel</Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UploadSalesFile.layout = {
    breadcrumbs: [
        { title: 'Sales', href: salesIndex() },
        { title: 'Imports', href: index() },
        { title: 'Upload', href: create() },
    ],
};
