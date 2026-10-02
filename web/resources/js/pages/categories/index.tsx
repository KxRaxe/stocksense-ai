import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber } from '@/lib/format';
import { create, destroy, edit, index } from '@/routes/categories';
import type { CategoryRow } from '@/types';

type Props = {
    categories: CategoryRow[];
    can: { manage: boolean };
};

export default function CategoriesIndex({ categories, can }: Props) {
    const [deleting, setDeleting] = useState<CategoryRow | null>(null);

    const remove = (category: CategoryRow) => {
        router.delete(destroy.url(category.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'Could not delete.'),
        });
    };

    return (
        <>
            <Head title="Categories" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Categories"
                        description="Group products and set how much safety stock each group should hold."
                    />
                    {can.manage && (
                        <Button asChild data-test="new-category-button">
                            <Link href={create()}>
                                <Plus />
                                New category
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">Name</TableHead>
                                <TableHead>Service level</TableHead>
                                <TableHead className="text-right">
                                    Products
                                </TableHead>
                                <TableHead className="pr-4 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No categories yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {categories.map((category) => (
                                <TableRow
                                    key={category.id}
                                    data-test={`category-row-${category.id}`}
                                >
                                    <TableCell className="pl-4">
                                        <div className="font-medium">
                                            {category.name}
                                        </div>
                                        {category.description && (
                                            <div className="max-w-md truncate text-xs whitespace-normal text-muted-foreground">
                                                {category.description}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {category.service_level}%
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(category.products_count)}
                                    </TableCell>
                                    <TableCell className="pr-4 text-right">
                                        {can.manage && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={edit(category.id)}
                                                    >
                                                        Edit
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setDeleting(category)
                                                    }
                                                    data-test={`delete-category-${category.id}`}
                                                >
                                                    Delete
                                                </Button>
                                            </>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.name ?? 'category'}?`}
                description="This cannot be undone. A category that still has products cannot be deleted."
                confirmLabel="Delete"
                destructive
                testId="confirm-delete-category"
                onConfirm={() => deleting && remove(deleting)}
            />
        </>
    );
}

CategoriesIndex.layout = {
    breadcrumbs: [{ title: 'Categories', href: index() }],
};
