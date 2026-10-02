import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import SalesEntryForm from '@/components/sales/sales-entry-form';
import { create, index } from '@/routes/sales';
import type { SaleProductOption } from '@/types';

type Props = {
    products: SaleProductOption[];
    today: string;
};

export default function RecordSales({ products, today }: Props) {
    return (
        <>
            <Head title="Record sales" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Record sales"
                    description="Enter a day's sales. Each line takes that product off the shelf. For a lot of data, import a file instead."
                />
                <SalesEntryForm products={products} today={today} />
            </div>
        </>
    );
}

RecordSales.layout = {
    breadcrumbs: [
        { title: 'Sales', href: index() },
        { title: 'Record sales', href: create() },
    ],
};
