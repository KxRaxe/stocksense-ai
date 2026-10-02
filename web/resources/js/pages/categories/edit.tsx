import { Head } from '@inertiajs/react';
import CategoryForm from '@/components/catalog/category-form';
import Heading from '@/components/heading';
import { index, update } from '@/routes/categories';
import type { CategoryRow } from '@/types';

export default function EditCategory({ category }: { category: CategoryRow }) {
    return (
        <>
            <Head title={`Edit ${category.name}`} />

            <div className="space-y-6 p-4">
                <Heading
                    title={category.name}
                    description={`${category.products_count} product${category.products_count === 1 ? '' : 's'} in this category.`}
                />
                <CategoryForm
                    action={update.form(category.id)}
                    category={category}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}

EditCategory.layout = {
    breadcrumbs: [
        { title: 'Categories', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Edit category', href: index() },
    ],
};
