import { Form, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/categories';
import type { CategoryRow } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

type Props = {
    /** Wayfinder form definition, e.g. `store.form()` or `update.form(id)`. */
    action: RouteFormDefinition<'post'>;
    category?: CategoryRow;
    /** The service level a new category starts with (a system setting). */
    defaultServiceLevel?: number;
    submitLabel: string;
};

export default function CategoryForm({
    action,
    category,
    defaultServiceLevel = 95,
    submitLabel,
}: Props) {
    return (
        <Form {...action} className="max-w-xl space-y-6">
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            defaultValue={category?.name}
                            required
                            autoComplete="off"
                            placeholder="e.g. Hardware and construction"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="description">Description</Label>
                        <Textarea
                            id="description"
                            name="description"
                            defaultValue={category?.description ?? ''}
                            placeholder="Optional"
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="service_level">
                            Target service level (%)
                        </Label>
                        <Input
                            id="service_level"
                            name="service_level"
                            type="number"
                            step="0.1"
                            min="50"
                            max="99.9"
                            defaultValue={
                                category?.service_level ?? defaultServiceLevel
                            }
                            required
                            className="max-w-40"
                        />
                        <p className="text-sm text-muted-foreground">
                            How often you want to have stock when a customer
                            asks. Higher means more safety stock. 95% is a
                            common starting point.
                        </p>
                        <InputError message={errors.service_level} />
                    </div>

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            disabled={processing}
                            data-test="save-category-button"
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
