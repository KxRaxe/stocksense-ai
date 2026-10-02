import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import StockStatusBadge from '@/components/catalog/stock-status-badge';
import {
    RestockDialog,
    StockCountDialog,
} from '@/components/catalog/stock-dialogs';
import ConfirmDialog from '@/components/confirm-dialog';
import Heading from '@/components/heading';
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
import { formatDateTime, formatMoney, formatNumber } from '@/lib/format';
import { archive, edit, index, restore } from '@/routes/products';
import type { ProductDetails, StockMovementRow, StockStatus } from '@/types';

type Props = {
    product: ProductDetails;
    stock: { on_hand: number; on_order: number; status: StockStatus };
    movements: StockMovementRow[];
    can: { manage: boolean; adjust: boolean };
};

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-sm text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-semibold tracking-tight">
                {value}
            </div>
        </div>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="font-medium">{value}</dd>
        </div>
    );
}

export default function ShowProduct({ product, stock, movements, can }: Props) {
    const [archiving, setArchiving] = useState(false);

    const toggleArchive = (url: string) =>
        router.patch(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setArchiving(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ?? 'Something went wrong.',
                    ),
            },
        );

    return (
        <>
            <Head title={product.name} />

            <div className="space-y-8 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <Heading
                            title={product.name}
                            description={`${product.sku} · ${product.category}`}
                        />
                        <div className="-mt-6 flex gap-2">
                            <StockStatusBadge status={stock.status} />
                            {!product.is_active && (
                                <Badge variant="secondary">Archived</Badge>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {can.adjust && product.is_active && (
                            <>
                                <RestockDialog
                                    productId={product.id}
                                    productName={product.name}
                                    unit={product.unit}
                                    onHand={stock.on_hand}
                                />
                                <StockCountDialog
                                    productId={product.id}
                                    productName={product.name}
                                    unit={product.unit}
                                    onHand={stock.on_hand}
                                />
                            </>
                        )}
                        {can.manage && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={edit(product.id)}
                                        data-test="edit-product-button"
                                    >
                                        Edit
                                    </Link>
                                </Button>
                                {product.is_active ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setArchiving(true)}
                                        data-test="archive-product-button"
                                    >
                                        Archive
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            toggleArchive(
                                                restore.url(product.id),
                                            )
                                        }
                                        data-test="restore-product-button"
                                    >
                                        Restore
                                    </Button>
                                )}
                            </>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label={`On hand (${product.unit})`}
                        value={formatNumber(stock.on_hand)}
                    />
                    <Stat
                        label="On order"
                        value={formatNumber(stock.on_order)}
                    />
                    <Stat
                        label="Reorder point"
                        value={
                            product.reorder_point_override === null
                                ? 'Not set'
                                : formatNumber(product.reorder_point_override)
                        }
                    />
                    <Stat
                        label="Lead time"
                        value={`${product.lead_time_days} days`}
                    />
                </div>

                <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Detail
                        label="Unit cost"
                        value={formatMoney(product.unit_cost)}
                    />
                    <Detail
                        label="Unit price"
                        value={formatMoney(product.unit_price)}
                    />
                    <Detail
                        label="Minimum order"
                        value={formatNumber(product.moq)}
                    />
                    <Detail
                        label="Pack size"
                        value={formatNumber(product.pack_size)}
                    />
                    <Detail
                        label="Safety stock"
                        value={
                            product.safety_stock_override === null
                                ? 'Calculated'
                                : formatNumber(product.safety_stock_override)
                        }
                    />
                </dl>

                <div className="space-y-3">
                    <Heading
                        variant="small"
                        title="Stock history"
                        description="The 25 most recent changes, newest first."
                    />
                    <div className="rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-4">When</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead className="text-right">
                                        Change
                                    </TableHead>
                                    <TableHead>Note</TableHead>
                                    <TableHead className="pr-4">By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {movements.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="p-6 text-center text-muted-foreground"
                                        >
                                            No stock recorded yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {movements.map((movement) => (
                                    <TableRow
                                        key={movement.id}
                                        data-test={`movement-${movement.id}`}
                                    >
                                        <TableCell className="pl-4">
                                            {formatDateTime(
                                                movement.occurred_at,
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {movement.type_label}
                                        </TableCell>
                                        <TableCell
                                            className={`text-right font-medium ${
                                                movement.quantity < 0
                                                    ? 'text-destructive'
                                                    : 'text-emerald-600 dark:text-emerald-400'
                                            }`}
                                        >
                                            {movement.quantity > 0 ? '+' : ''}
                                            {formatNumber(movement.quantity)}
                                        </TableCell>
                                        <TableCell className="max-w-xs truncate text-muted-foreground">
                                            {movement.note ?? '-'}
                                        </TableCell>
                                        <TableCell className="pr-4 text-muted-foreground">
                                            {movement.user ?? 'System'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={archiving}
                onOpenChange={setArchiving}
                title={`Archive ${product.name}?`}
                description="It disappears from the product and stock lists but keeps all of its history. You can restore it later."
                confirmLabel="Archive"
                destructive
                testId="confirm-archive-button"
                onConfirm={() => toggleArchive(archive.url(product.id))}
            />
        </>
    );
}

ShowProduct.layout = {
    breadcrumbs: [
        { title: 'Products', href: index() },
        // The last crumb is shown as plain text, so its href is never used.
        { title: 'Product', href: index() },
    ],
};
