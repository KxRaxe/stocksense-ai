import { Head } from '@inertiajs/react';
import ProductForm from '@/components/catalog/product-form';
import Heading from '@/components/heading';
import { create, index, store } from '@/routes/products';
import type { Option } from '@/types';

export default function CreateProduct({
    categories,
}: {
    categories: Option[];
}) {
    return (
        <>
            <Head title="New product" />

            <div className="space-y-6 p-4">
                <Heading title="New product" />
                <ProductForm
                    action={store.form()}
                    categories={categories}
                    withOpeningStock
                    submitLabel="Create product"
                />
            </div>
        </>
    );
}

CreateProduct.layout = {
    breadcrumbs: [
        { title: 'Products', href: index() },
        { title: 'New product', href: create() },
    ],
};
