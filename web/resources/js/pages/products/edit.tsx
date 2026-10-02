import { Head } from '@inertiajs/react';
import ProductForm from '@/components/catalog/product-form';
import Heading from '@/components/heading';
import { index, update } from '@/routes/products';
import type { Option, ProductDetails } from '@/types';

type Props = {
    product: ProductDetails;
    categories: Option[];
};

export default function EditProduct({ product, categories }: Props) {
    return (
        <>
            <Head title={`Edit ${product.name}`} />

            <div className="space-y-6 p-4">
                <Heading
                    title={product.name}
                    description="Stock levels are not edited here. Record a restock or a stock count from the product page."
                />
                <ProductForm
                    action={update.form(product.id)}
                    categories={categories}
                    product={product}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}

EditProduct.layout = {
    breadcrumbs: [
        { title: 'Products', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Edit product', href: index() },
    ],
};
