import { todayLocal } from '@/lib/replenishment';
import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { adjust, restock } from '@/routes/products';

type Props = {
    productId: number;
    productName: string;
    unit: string;
    onHand: number;
};

/** Today's date as YYYY-MM-DD in the browser's own time zone. */
/** Record goods received from a supplier. */
export function RestockDialog({ productId, productName, unit }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button data-test="restock-button">Record restock</Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Record restock</DialogTitle>
                <DialogDescription>
                    Goods received for {productName}. This adds to the stock on
                    hand.
                </DialogDescription>

                <Form
                    {...restock.form(productId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="restock-quantity">
                                    Quantity received ({unit})
                                </Label>
                                <Input
                                    id="restock-quantity"
                                    name="quantity"
                                    type="number"
                                    min="1"
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.quantity} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="restock-date">
                                    Date received
                                </Label>
                                <Input
                                    id="restock-date"
                                    name="received_on"
                                    type="date"
                                    max={todayLocal()}
                                    defaultValue={todayLocal()}
                                />
                                <InputError message={errors.received_on} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="restock-note">
                                    Note (optional)
                                </Label>
                                <Input
                                    id="restock-note"
                                    name="note"
                                    placeholder="e.g. Supplier invoice number"
                                    autoComplete="off"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="confirm-restock-button"
                                >
                                    {processing && <Spinner />}
                                    Save restock
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/** Set stock to what was physically counted. */
export function StockCountDialog({
    productId,
    productName,
    unit,
    onHand,
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" data-test="count-button">
                    Count stock
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Count stock</DialogTitle>
                <DialogDescription>
                    Enter how many {productName} are on the shelf. The system
                    records the difference from the {onHand} {unit} it expects.
                </DialogDescription>

                <Form
                    {...adjust.form(productId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="count-quantity">
                                    Counted quantity ({unit})
                                </Label>
                                <Input
                                    id="count-quantity"
                                    name="counted"
                                    type="number"
                                    min="0"
                                    defaultValue={onHand > 0 ? onHand : 0}
                                    required
                                    autoFocus
                                />
                                <InputError message={errors.counted} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="count-note">Reason</Label>
                                <Input
                                    id="count-note"
                                    name="note"
                                    placeholder="e.g. Monthly stock count, damaged goods"
                                    required
                                    autoComplete="off"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="confirm-count-button"
                                >
                                    {processing && <Spinner />}
                                    Save count
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
