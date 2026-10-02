import { Head, Link } from '@inertiajs/react';
import { FileUp, Plus, X } from 'lucide-react';
import ListFilters from '@/components/catalog/list-filters';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney } from '@/lib/format';
import { create, index, show } from '@/routes/products';
import { create as importCreate } from '@/routes/products/imports';
import type { Option, Paginated, ProductRow } from '@/types';

type Props = {
    products: Paginated<ProductRow>;
    filters: {
        search: string;
        category: number | null;
        status: string;
        import: number | null;
    };
    categories: Option[];
    can: { manage: boolean };
};

export default function ProductsIndex({
    products,
    filters,
    categories,
    can,
}: Props) {
    return (
        <>
            <Head title="Products" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="Products"
                        description="Everything you sell. Open a product to see its stock history."
                    />
                    {can.manage && (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                variant="outline"
                                asChild
                                data-test="import-products-button"
                            >
                                <Link href={importCreate()}>
                                    <FileUp />
                                    Import from a file
                                </Link>
                            </Button>
                            <Button asChild data-test="new-product-button">
                                <Link href={create()}>
                                    <Plus />
                                    New product
                                </Link>
                            </Button>
                        </div>
                    )}
                </div>

                <ListFilters
                    url={index().url}
                    filters={filters}
                    categories={categories}
                    extra={{
                        name: 'status',
                        label: 'Status',
                        choices: [
                            { value: 'active', label: 'Active' },
                            { value: 'archived', label: 'Archived' },
                            { value: 'all', label: 'All' },
                        ],
                    }}
                />

                {filters.import !== null && (
                    <div className="flex items-center gap-2 text-sm">
                        <span className="rounded-md bg-muted px-2 py-1">
                            Showing products from import #{filters.import}
                        </span>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={index()}>
                                <X />
                                Show all
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">SKU</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-right">
                                    Cost
                                </TableHead>
                                <TableHead className="text-right">
                                    Price
                                </TableHead>
                                <TableHead className="pr-4">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No products match.
                                    </TableCell>
                                </TableRow>
                            )}
                            {products.data.map((product) => (
                                <TableRow
                                    key={product.id}
                                    data-test={`product-row-${product.id}`}
                                >
                                    <TableCell className="pl-4 font-mono text-xs">
                                        {product.sku}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={show(product.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {product.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {product.category}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(product.unit_cost)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(product.unit_price)}
                                    </TableCell>
                                    <TableCell className="pr-4">
                                        {product.is_active ? (
                                            <Badge variant="outline">
                                                Active
                                            </Badge>
                                        ) : (
                                            <Badge variant="secondary">
                                                Archived
                                            </Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={products} />
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [{ title: 'Products', href: index() }],
};
