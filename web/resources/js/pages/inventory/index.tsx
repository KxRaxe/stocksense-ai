import { Head, Link } from '@inertiajs/react';
import ListFilters from '@/components/catalog/list-filters';
import StockStatusBadge from '@/components/catalog/stock-status-badge';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatNumber } from '@/lib/format';
import { index } from '@/routes/inventory';
import { show } from '@/routes/products';
import type { Option, Paginated, StockRow } from '@/types';

type Props = {
    products: Paginated<StockRow>;
    filters: { search: string; category: number | null; stock: string };
    categories: Option[];
};

export default function InventoryIndex({
    products,
    filters,
    categories,
}: Props) {
    return (
        <>
            <Head title="Inventory" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Inventory"
                    description="What is on hand for every active product. Open a product to record a delivery or a stock count."
                />

                <ListFilters
                    url={index().url}
                    filters={filters}
                    categories={categories}
                    extra={{
                        name: 'stock',
                        label: 'Stock level',
                        choices: [
                            { value: 'all', label: 'All stock levels' },
                            { value: 'out_of_stock', label: 'Out of stock' },
                            { value: 'low', label: 'Low stock' },
                            { value: 'ok', label: 'In stock' },
                        ],
                    }}
                />

                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">Product</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    On order
                                </TableHead>
                                <TableHead className="text-right">
                                    Reorder point
                                </TableHead>
                                <TableHead className="text-right">
                                    Lead time
                                </TableHead>
                                <TableHead className="pr-4">Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No products match.
                                    </TableCell>
                                </TableRow>
                            )}
                            {products.data.map((product) => (
                                <TableRow
                                    key={product.id}
                                    data-test={`stock-row-${product.id}`}
                                >
                                    <TableCell className="pl-4">
                                        <Link
                                            href={show(product.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {product.name}
                                        </Link>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {product.sku}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {product.category}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        {formatNumber(product.on_hand)}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {product.unit}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(product.on_order)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {product.reorder_point === null
                                            ? '-'
                                            : formatNumber(
                                                  product.reorder_point,
                                              )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {product.lead_time_days} days
                                    </TableCell>
                                    <TableCell className="pr-4">
                                        <StockStatusBadge
                                            status={product.status}
                                        />
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

InventoryIndex.layout = {
    breadcrumbs: [{ title: 'Inventory', href: index() }],
};
