import { Head } from '@inertiajs/react';
import CategoryForm from '@/components/catalog/category-form';
import Heading from '@/components/heading';
import { create, index, store } from '@/routes/categories';

export default function CreateCategory({
    defaultServiceLevel,
}: {
    defaultServiceLevel: number;
}) {
    return (
        <>
            <Head title="New category" />

            <div className="space-y-6 p-4">
                <Heading title="New category" />
                <CategoryForm
                    action={store.form()}
                    defaultServiceLevel={defaultServiceLevel}
                    submitLabel="Create category"
                />
            </div>
        </>
    );
}

CreateCategory.layout = {
    breadcrumbs: [
        { title: 'Categories', href: index() },
        { title: 'New category', href: create() },
    ],
};
