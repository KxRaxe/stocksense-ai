import { Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, formatNumber } from '@/lib/format';
import { index, store } from '@/routes/sales';
import type { SaleProductOption } from '@/types';

type Item = { product_id: string; quantity: string; unit_price: string };

type Props = {
    products: SaleProductOption[];
    /** Today's date, YYYY-MM-DD: the default and the latest date allowed. */
    today: string;
};

const blankItem = (): Item => ({
    product_id: '',
    quantity: '',
    unit_price: '',
});

/** The money value of one line, or 0 while it is incomplete. */
const lineTotal = (item: Item): number => {
    const quantity = Number(item.quantity);
    const price = Number(item.unit_price);

    return Number.isFinite(quantity) && Number.isFinite(price)
        ? quantity * price
        : 0;
};

/**
 * A day's sales entered by hand: one date, then a line for each product sold.
 * Choosing a product fills in its usual price, which can be changed.
 */
export default function SalesEntryForm({ products, today }: Props) {
    const form = useForm<{ sold_on: string; items: Item[] }>({
        sold_on: today,
        items: [blankItem()],
    });

    const errors = form.errors as Record<string, string | undefined>;
    const productById = new Map(products.map((p) => [String(p.id), p]));

    const setItem = (position: number, changes: Partial<Item>) =>
        form.setData(
            'items',
            form.data.items.map((item, i) =>
                i === position ? { ...item, ...changes } : item,
            ),
        );

    const chooseProduct = (position: number, productId: string) => {
        const product = productById.get(productId);

        setItem(position, {
            product_id: productId,
            // Start from the usual price; the person can change it.
            unit_price: product ? product.unit_price.toFixed(2) : '',
        });
    };

    const addLine = () =>
        form.setData('items', [...form.data.items, blankItem()]);

    const removeLine = (position: number) =>
        form.setData(
            'items',
            form.data.items.filter((_, i) => i !== position),
        );

    const complete = form.data.items.every(
        (item) => item.product_id !== '' && Number(item.quantity) >= 1,
    );
    const total = form.data.items.reduce(
        (sum, item) => sum + lineTotal(item),
        0,
    );

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post(store().url);
            }}
            className="max-w-4xl space-y-6"
        >
            <div className="grid max-w-xs gap-2">
                <Label htmlFor="sold_on">Date of sale</Label>
                <Input
                    id="sold_on"
                    type="date"
                    value={form.data.sold_on}
                    max={today}
                    onChange={(event) =>
                        form.setData('sold_on', event.target.value)
                    }
                    required
                />
                <InputError message={errors.sold_on} />
            </div>

            <div className="space-y-3">
                <div className="hidden grid-cols-[1fr_6rem_8rem_7rem_2.5rem] gap-3 text-sm font-medium sm:grid">
                    <span>Product</span>
                    <span>Quantity</span>
                    <span>Unit price (₱)</span>
                    <span className="text-right">Line total</span>
                    <span />
                </div>

                {form.data.items.map((item, position) => {
                    const product = productById.get(item.product_id);
                    const quantity = Number(item.quantity);
                    const overSold =
                        product !== undefined && quantity > product.on_hand;

                    return (
                        <div
                            key={position}
                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_6rem_8rem_7rem_2.5rem] sm:items-start sm:border-0 sm:p-0"
                            data-test={`sale-line-${position}`}
                        >
                            <div className="grid gap-1">
                                <NativeSelect
                                    value={item.product_id}
                                    onChange={(event) =>
                                        chooseProduct(
                                            position,
                                            event.target.value,
                                        )
                                    }
                                    aria-label={`Product, line ${position + 1}`}
                                >
                                    <option value="">Choose a product</option>
                                    {products.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                        >
                                            {option.name} ({option.sku})
                                        </option>
                                    ))}
                                </NativeSelect>
                                {product && (
                                    <p
                                        className={`text-xs ${overSold ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'}`}
                                    >
                                        {overSold
                                            ? `Only ${formatNumber(product.on_hand)} ${product.unit} on hand. Stock will go negative.`
                                            : `${formatNumber(product.on_hand)} ${product.unit} on hand`}
                                    </p>
                                )}
                                <InputError
                                    message={
                                        errors[`items.${position}.product_id`]
                                    }
                                />
                            </div>

                            <div className="grid gap-1">
                                <Input
                                    type="number"
                                    min="1"
                                    value={item.quantity}
                                    onChange={(event) =>
                                        setItem(position, {
                                            quantity: event.target.value,
                                        })
                                    }
                                    aria-label={`Quantity, line ${position + 1}`}
                                    placeholder="Qty"
                                />
                                <InputError
                                    message={
                                        errors[`items.${position}.quantity`]
                                    }
                                />
                            </div>

                            <div className="grid gap-1">
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={item.unit_price}
                                    onChange={(event) =>
                                        setItem(position, {
                                            unit_price: event.target.value,
                                        })
                                    }
                                    aria-label={`Unit price, line ${position + 1}`}
                                />
                                <InputError
                                    message={
                                        errors[`items.${position}.unit_price`]
                                    }
                                />
                            </div>

                            <div className="py-2 text-right text-sm font-medium">
                                {formatMoney(lineTotal(item))}
                            </div>

                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => removeLine(position)}
                                disabled={form.data.items.length === 1}
                                aria-label={`Remove line ${position + 1}`}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    );
                })}

                <InputError message={errors.items} />

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={addLine}
                    data-test="add-line-button"
                >
                    <Plus />
                    Add a line
                </Button>
            </div>

            <div className="flex items-center justify-between border-t pt-4">
                <div className="flex items-center gap-3">
                    <Button
                        type="submit"
                        disabled={form.processing || !complete}
                        data-test="save-sales-button"
                    >
                        {form.processing && <Spinner />}
                        Record{' '}
                        {form.data.items.length === 1
                            ? 'sale'
                            : `${form.data.items.length} sales`}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={index()}>Cancel</Link>
                    </Button>
                </div>
                <div className="text-sm">
                    Total{' '}
                    <span className="text-lg font-semibold">
                        {formatMoney(total)}
                    </span>
                </div>
            </div>
        </form>
    );
}
