import { Head, Link, router } from '@inertiajs/react';
import { FileUp, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import SalesFilters from '@/components/sales/sales-filters';
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
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { create, destroy, index } from '@/routes/sales';
import {
    create as importCreate,
    show as importShow,
} from '@/routes/sales/imports';
import type {
    Paginated,
    SaleRow,
    SalesFilters as Filters,
    SalesTotals,
} from '@/types';

type Props = {
    sales: Paginated<SaleRow>;
    filters: Filters;
    totals: SalesTotals;
    can: { enter: boolean; import: boolean };
};

export default function SalesIndex({ sales, filters, totals, can }: Props) {
    const [deleting, setDeleting] = useState<SaleRow | null>(null);

    const remove = (sale: SaleRow) =>
        router.delete(destroy.url(sale.id), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
            onError: (errors) =>
                toast.error(Object.values(errors)[0] ?? 'Could not delete.'),
        });

    return (
        <>
            <Head title="Sales" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Sales"
                        description="The sales history the forecasts learn from."
                    />
                    <div className="flex gap-2">
                        {can.import && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={importCreate()}
                                    data-test="import-sales-button"
                                >
                                    <FileUp />
                                    Import from a file
                                </Link>
                            </Button>
                        )}
                        {can.enter && (
                            <Button asChild data-test="record-sales-button">
                                <Link href={create()}>
                                    <Plus />
                                    Record sales
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <SalesFilters url={index().url} filters={filters} />

                <p
                    className="text-sm text-muted-foreground"
                    data-test="sales-totals"
                >
                    {formatNumber(totals.lines)}{' '}
                    {totals.lines === 1 ? 'line' : 'lines'} ·{' '}
                    {formatNumber(totals.units)} units ·{' '}
                    {formatMoney(totals.revenue)}
                </p>

                <div className="rounded-xl border-2 bg-card shadow-brutal">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-4">Date</TableHead>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">
                                    Qty
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit price
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Source</TableHead>
                                <TableHead className="pr-4 text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sales.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No sales match.
                                    </TableCell>
                                </TableRow>
                            )}
                            {sales.data.map((sale) => (
                                <TableRow
                                    key={sale.id}
                                    data-test={`sale-row-${sale.id}`}
                                >
                                    <TableCell className="pl-4">
                                        {formatDate(sale.sold_on)}
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-medium">
                                            {sale.product.name}
                                        </div>
                                        <div className="font-mono text-xs text-muted-foreground">
                                            {sale.product.sku}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatNumber(sale.quantity)}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            {sale.product.unit}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(sale.unit_price)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        {formatMoney(sale.total)}
                                    </TableCell>
                                    <TableCell>
                                        {sale.import_batch_id !== null ? (
                                            <Link
                                                href={importShow(
                                                    sale.import_batch_id,
                                                )}
                                            >
                                                <Badge variant="secondary">
                                                    Import #
                                                    {sale.import_batch_id}
                                                </Badge>
                                            </Link>
                                        ) : (
                                            <Badge variant="outline">
                                                By hand
                                                {sale.user
                                                    ? ` · ${sale.user}`
                                                    : ''}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="pr-4 text-right">
                                        {can.enter &&
                                            sale.source === 'manual' && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    onClick={() =>
                                                        setDeleting(sale)
                                                    }
                                                    aria-label={`Delete sale ${sale.id}`}
                                                    data-test={`delete-sale-${sale.id}`}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={sales} />
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete this sale?"
                description={
                    deleting
                        ? `${formatNumber(deleting.quantity)} × ${deleting.product.name} on ${formatDate(deleting.sold_on)}. The stock is put back. This is recorded in the stock history.`
                        : ''
                }
                confirmLabel="Delete"
                destructive
                testId="confirm-delete-sale"
                onConfirm={() => deleting && remove(deleting)}
            />
        </>
    );
}

SalesIndex.layout = {
    breadcrumbs: [{ title: 'Sales', href: index() }],
};
