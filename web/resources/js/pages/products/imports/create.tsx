import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import UploadImportForm from '@/components/imports/upload-import-form';
import type { UploadImportProps } from '@/components/imports/upload-import-form';
import { index as productsIndex } from '@/routes/products';
import { create, index } from '@/routes/products/imports';

export default function UploadProductFile(props: UploadImportProps) {
    return (
        <>
            <Head title="Upload a product file" />

            <div className="space-y-8 p-4">
                <Heading
                    title="Upload a product file"
                    description="A CSV or Excel (.xlsx) file with one row per product. You will check the columns and see what would happen before anything is imported."
                />

                <UploadImportForm
                    {...props}
                    requirements={
                        <>
                            <li>
                                A first row of column names, then one product
                                per row.
                            </li>
                            <li>
                                <strong className="text-foreground">SKU</strong>
                                ,{' '}
                                <strong className="text-foreground">
                                    product name
                                </strong>{' '}
                                and{' '}
                                <strong className="text-foreground">
                                    category
                                </strong>{' '}
                                are required. Everything else is optional; what
                                a new product is not given starts out as it
                                would on the New product form.
                            </li>
                            <li>
                                Opening stock sets how many a new product starts
                                with. It is not used for products that already
                                exist.
                            </li>
                        </>
                    }
                />
            </div>
        </>
    );
}

UploadProductFile.layout = {
    breadcrumbs: [
        { title: 'Products', href: productsIndex() },
        { title: 'Imports', href: index() },
        { title: 'Upload', href: create() },
    ],
};
