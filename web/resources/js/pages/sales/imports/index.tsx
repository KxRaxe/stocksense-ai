import ImportsIndex from '@/components/imports/imports-index';
import type { ImportsIndexProps } from '@/components/imports/imports-index';
import { index as salesIndex } from '@/routes/sales';
import { index } from '@/routes/sales/imports';

export default function SalesImports(props: ImportsIndexProps) {
    return (
        <ImportsIndex
            {...props}
            title="Sales imports"
            description="Files of sales you have uploaded. Open one to see what happened to each row, or to undo it."
        />
    );
}

SalesImports.layout = {
    breadcrumbs: [
        { title: 'Sales', href: salesIndex() },
        { title: 'Imports', href: index() },
    ],
};
