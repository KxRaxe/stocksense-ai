import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { orderingRules } from '@/lib/replenishment';
import { accept, adjust, cancel, dismiss } from '@/routes/recommendations';
import type { DecisionKind, RecommendationRow } from '@/types';

const routes = { accept, adjust, dismiss, cancel };

type Copy = {
    title: string;
    /** An example for the note field, fitting what is being done. */
    example: string;
    description: (row: RecommendationRow) => string;
    submit: string;
    destructive?: boolean;
};

const copy: Record<DecisionKind, Copy> = {
    accept: {
        title: 'Accept this recommendation',
        example: 'e.g. Ordered by phone from the usual supplier',
        description: (row) =>
            `${row.recommended_qty} ${row.product.unit} of ${row.product.name} will count as on order until you record the restock. Nothing is ordered for you: place the order with your supplier.`,
        submit: 'Accept',
    },
    adjust: {
        title: 'Accept with a different quantity',
        example: 'e.g. Supplier offers a better price on a bigger order',
        description: (row) =>
            `Choose how many ${row.product.unit} of ${row.product.name} you will order. That amount counts as on order until you record the restock.`,
        submit: 'Accept this quantity',
    },
    dismiss: {
        title: 'Dismiss this recommendation',
        example: 'e.g. Discontinuing this product',
        description: (row) =>
            `${row.product.name} will be left out of the recommendations for a few days, then looked at again.`,
        submit: 'Dismiss',
    },
    cancel: {
        title: 'Cancel this order',
        example: 'e.g. Supplier could not deliver',
        description: (row) =>
            `The ${row.final_qty ?? row.recommended_qty} ${row.product.unit} of ${row.product.name} will no longer count as on order, so it may be recommended again.`,
        submit: 'Cancel order',
        destructive: true,
    },
};

type Props = {
    /** What the person is doing; null while no dialog is open. */
    kind: DecisionKind | null;
    row: RecommendationRow;
    onClose: () => void;
};

/**
 * The one dialog behind Accept, Adjust, Dismiss and Cancel: a short
 * explanation, the quantity when it can be changed, and an optional note.
 * The server decides whether it is still allowed.
 */
export default function DecisionDialog({ kind, row, onClose }: Props) {
    if (kind === null) {
        return null;
    }

    const { title, example, description, submit, destructive } = copy[kind];
    const rules = orderingRules(
        row.product.pack_size,
        row.product.moq,
        row.product.unit,
    );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent data-test={`${kind}-dialog`}>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description(row)}</DialogDescription>

                <Form
                    {...routes[kind].form(row.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={onClose}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {errors.recommendation && (
                                <p
                                    className="text-sm text-destructive"
                                    role="alert"
                                    data-test="decision-error"
                                >
                                    {errors.recommendation}
                                </p>
                            )}

                            {kind === 'adjust' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="decision-quantity">
                                        Quantity ({row.product.unit})
                                    </Label>
                                    <Input
                                        id="decision-quantity"
                                        name="quantity"
                                        type="number"
                                        min="1"
                                        defaultValue={
                                            row.recommended_qty > 0
                                                ? row.recommended_qty
                                                : ''
                                        }
                                        required
                                        autoFocus
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Recommended: {row.recommended_qty}{' '}
                                        {row.product.unit}.
                                        {rules ? ` ${rules}.` : ''}
                                    </p>
                                    <InputError message={errors.quantity} />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="decision-note">
                                    Note (optional)
                                </Label>
                                <Input
                                    id="decision-note"
                                    name="note"
                                    maxLength={500}
                                    placeholder={example}
                                    autoComplete="off"
                                />
                                <InputError message={errors.note} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary" type="button">
                                        Back
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant={
                                        destructive ? 'destructive' : 'default'
                                    }
                                    disabled={processing}
                                    data-test="confirm-decision-button"
                                >
                                    {processing && <Spinner />}
                                    {submit}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
