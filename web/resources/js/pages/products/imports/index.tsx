import ImportsIndex from '@/components/imports/imports-index';
import type { ImportsIndexProps } from '@/components/imports/imports-index';
import { index as productsIndex } from '@/routes/products';
import { index } from '@/routes/products/imports';

export default function ProductImports(props: ImportsIndexProps) {
    return (
        <ImportsIndex
            {...props}
            title="Product imports"
            description="Product lists you have uploaded. Open one to see what happened to each row, or to undo it."
        />
    );
}

ProductImports.layout = {
    breadcrumbs: [
        { title: 'Products', href: productsIndex() },
        { title: 'Imports', href: index() },
    ],
};
