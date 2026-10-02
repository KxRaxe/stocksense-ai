import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import UploadImportForm from '@/components/imports/upload-import-form';
import type { UploadImportProps } from '@/components/imports/upload-import-form';
import { index as salesIndex } from '@/routes/sales';
import { create, index } from '@/routes/sales/imports';

export default function UploadSalesFile(props: UploadImportProps) {
    return (
        <>
            <Head title="Upload a sales file" />

            <div className="space-y-8 p-4">
                <Heading
                    title="Upload a sales file"
                    description="A CSV or Excel (.xlsx) file with one row per sale. You will check the columns before anything is imported."
                />

                <UploadImportForm
                    {...props}
                    requirements={
                        <>
                            <li>
                                A first row of column names, then one sale per
                                row.
                            </li>
                            <li>
                                <strong className="text-foreground">
                                    Date
                                </strong>
                                ,{' '}
                                <strong className="text-foreground">SKU</strong>{' '}
                                and{' '}
                                <strong className="text-foreground">
                                    quantity
                                </strong>{' '}
                                are required. Unit price is optional; if it is
                                empty the product's current price is used.
                            </li>
                            <li>
                                Rows that were imported before are skipped, so
                                it is safe to upload an overlapping file.
                            </li>
                        </>
                    }
                />
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
