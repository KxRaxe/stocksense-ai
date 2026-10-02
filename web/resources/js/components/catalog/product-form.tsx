import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { create as createCategory } from '@/routes/categories';
import { index } from '@/routes/products';
import type { Option, ProductDetails } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    /** Wayfinder form definition, e.g. `store.form()` or `update.form(id)`. */
    action: RouteFormDefinition<'post'>;
    categories: Option[];
    product?: ProductDetails;
    /** Opening stock can only be entered when creating a product. */
    withOpeningStock?: boolean;
    submitLabel: string;
};

function Field({
    label,
    htmlFor,
    error,
    hint,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && <p className="text-sm text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

export default function ProductForm({
    action,
    categories,
    product,
    withOpeningStock = false,
    submitLabel,
}: Props) {
    return (
        <Form {...action} className="max-w-3xl space-y-8">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-6 sm:grid-cols-2">
                        <Field
                            label="SKU"
                            htmlFor="sku"
                            error={errors.sku}
                            hint="Your own product code. Saved in capitals."
                        >
                            <Input
                                id="sku"
                                name="sku"
                                defaultValue={product?.sku}
                                required
                                autoComplete="off"
                                placeholder="e.g. HW-001"
                            />
                        </Field>

                        <Field label="Name" htmlFor="name" error={errors.name}>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={product?.name}
                                required
                                autoComplete="off"
                            />
                        </Field>

                        <Field
                            label="Category"
                            htmlFor="category_id"
                            error={errors.category_id}
                            hint={
                                categories.length === 0
                                    ? undefined
                                    : 'Sets the target service level for reordering.'
                            }
                        >
                            <NativeSelect
                                id="category_id"
                                name="category_id"
                                defaultValue={product?.category_id ?? ''}
                                required
                            >
                                <option value="" disabled>
                                    Choose a category
                                </option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            {categories.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    There are no categories yet.{' '}
                                    <Link
                                        href={createCategory()}
                                        className="underline underline-offset-4"
                                    >
                                        Create one first.
                                    </Link>
                                </p>
                            )}
                        </Field>

                        <Field
                            label="Unit"
                            htmlFor="unit"
                            error={errors.unit}
                            hint="How you count it: pc, box, kg, bag..."
                        >
                            <Input
                                id="unit"
                                name="unit"
                                defaultValue={product?.unit ?? 'pc'}
                                required
                                autoComplete="off"
                            />
                        </Field>

                        <Field
                            label="Unit cost (₱)"
                            htmlFor="unit_cost"
                            error={errors.unit_cost}
                            hint="What you pay your supplier."
                        >
                            <Input
                                id="unit_cost"
                                name="unit_cost"
                                type="number"
                                step="0.01"
                                min="0"
                                defaultValue={product?.unit_cost ?? 0}
                                required
                            />
                        </Field>

                        <Field
                            label="Unit price (₱)"
                            htmlFor="unit_price"
                            error={errors.unit_price}
                            hint="What you sell it for."
                        >
                            <Input
                                id="unit_price"
                                name="unit_price"
                                type="number"
                                step="0.01"
                                min="0"
                                defaultValue={product?.unit_price ?? 0}
                                required
                            />
                        </Field>
                    </div>

                    <div className="space-y-4">
                        <Heading
                            variant="small"
                            title="Reordering"
                            description="Used for low-stock warnings now, and for replenishment recommendations later."
                        />

                        <div className="grid gap-6 sm:grid-cols-2">
                            <Field
                                label="Lead time (days)"
                                htmlFor="lead_time_days"
                                error={errors.lead_time_days}
                                hint="From ordering to delivery."
                            >
                                <Input
                                    id="lead_time_days"
                                    name="lead_time_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    defaultValue={product?.lead_time_days ?? 7}
                                    required
                                />
                            </Field>

                            <Field
                                label="Reorder point"
                                htmlFor="reorder_point_override"
                                error={errors.reorder_point_override}
                                hint="Flag as low stock at or below this. Leave empty for none."
                            >
                                <Input
                                    id="reorder_point_override"
                                    name="reorder_point_override"
                                    type="number"
                                    min="0"
                                    defaultValue={
                                        product?.reorder_point_override ?? ''
                                    }
                                />
                            </Field>

                            <Field
                                label="Minimum order quantity"
                                htmlFor="moq"
                                error={errors.moq}
                                hint="The least your supplier will sell."
                            >
                                <Input
                                    id="moq"
                                    name="moq"
                                    type="number"
                                    min="1"
                                    defaultValue={product?.moq ?? 1}
                                    required
                                />
                            </Field>

                            <Field
                                label="Pack size"
                                htmlFor="pack_size"
                                error={errors.pack_size}
                                hint="Orders come in multiples of this."
                            >
                                <Input
                                    id="pack_size"
                                    name="pack_size"
                                    type="number"
                                    min="1"
                                    defaultValue={product?.pack_size ?? 1}
                                    required
                                />
                            </Field>

                            <Field
                                label="Safety stock"
                                htmlFor="safety_stock_override"
                                error={errors.safety_stock_override}
                                hint="Optional. Leave empty to let the system calculate it."
                            >
                                <Input
                                    id="safety_stock_override"
                                    name="safety_stock_override"
                                    type="number"
                                    min="0"
                                    defaultValue={
                                        product?.safety_stock_override ?? ''
                                    }
                                />
                            </Field>

                            {withOpeningStock && (
                                <Field
                                    label="Opening stock"
                                    htmlFor="opening_stock"
                                    error={errors.opening_stock}
                                    hint="How many you have right now."
                                >
                                    <Input
                                        id="opening_stock"
                                        name="opening_stock"
                                        type="number"
                                        min="0"
                                        defaultValue={0}
                                    />
                                </Field>
                            )}
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="save-product-button"
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
