import ShowImport from '@/components/imports/show-import';
import type { ShowImportProps } from '@/components/imports/show-import';
import { index as productsIndex } from '@/routes/products';
import { index } from '@/routes/products/imports';

export default function ShowProductImport(props: ShowImportProps) {
    return <ShowImport {...props} />;
}

ShowProductImport.layout = {
    breadcrumbs: [
        { title: 'Products', href: productsIndex() },
        { title: 'Imports', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Import', href: index() },
    ],
};
