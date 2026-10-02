import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { describeValue, stepFor } from '@/lib/settings';
import { reset, update } from '@/routes/system-settings';
import type { SettingField, SettingValue, SystemSettingsProps } from '@/types';

type Props = Pick<SystemSettingsProps, 'groups' | 'has_changes' | 'time_zone'>;

function Control({
    field,
    value,
    onChange,
}: {
    field: SettingField;
    value: SettingValue;
    onChange: (value: SettingValue) => void;
}) {
    const id = `setting-${field.key}`;

    switch (field.type) {
        case 'boolean':
            return (
                <Checkbox
                    id={id}
                    checked={value === true}
                    onCheckedChange={(checked) => onChange(checked === true)}
                    data-test={id}
                />
            );
        case 'choice':
            return (
                <NativeSelect
                    id={id}
                    value={String(value)}
                    onChange={(event) => onChange(Number(event.target.value))}
                    className="max-w-48"
                    data-test={id}
                >
                    {field.choices.map((choice) => (
                        <option key={choice.value} value={choice.value}>
                            {choice.label}
                        </option>
                    ))}
                </NativeSelect>
            );
        case 'time':
            return (
                <Input
                    id={id}
                    type="time"
                    value={String(value)}
                    onChange={(event) => onChange(event.target.value)}
                    className="max-w-32"
                    data-test={id}
                />
            );
        default:
            return (
                <div className="flex items-center gap-2">
                    <Input
                        id={id}
                        type="number"
                        value={String(value)}
                        min={field.min ?? undefined}
                        max={field.max ?? undefined}
                        step={stepFor(field)}
                        onChange={(event) => onChange(event.target.value)}
                        className="max-w-28"
                        data-test={id}
                    />
                    {field.unit && (
                        <span className="text-sm text-muted-foreground">
                            {field.unit}
                        </span>
                    )}
                </div>
            );
    }
}

/**
 * Every shop-wide setting, grouped, each with what it does and what the
 * default is. Saved together; "Restore defaults" clears every change.
 */
export default function SettingsForm({
    groups,
    has_changes,
    time_zone,
}: Props) {
    const [confirmingReset, setConfirmingReset] = useState(false);

    const form = useForm<{ values: Record<string, SettingValue> }>({
        values: Object.fromEntries(
            groups.flatMap((group) =>
                group.settings.map((field) => [field.key, field.value]),
            ),
        ),
    });

    const change = (key: string, value: SettingValue) =>
        form.setData('values', { ...form.data.values, [key]: value });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    const errorFor = (key: string) =>
        form.errors[`values.${key}` as keyof typeof form.errors];

    return (
        <>
            <form onSubmit={submit} className="space-y-8">
                {groups.map((group) => (
                    <fieldset
                        key={group.name}
                        className="space-y-6 rounded-lg border p-5"
                        data-test={`group-${group.name}`}
                    >
                        <legend className="px-1 text-sm font-medium">
                            {group.name}
                        </legend>

                        {group.settings.map((field) => {
                            const value = form.data.values[field.key];
                            const changed =
                                String(value) !== String(field.default);

                            return (
                                <div
                                    key={field.key}
                                    className="grid gap-2"
                                    data-test={`field-${field.key}`}
                                >
                                    <div
                                        className={
                                            field.type === 'boolean'
                                                ? 'flex items-center gap-2'
                                                : 'grid gap-2'
                                        }
                                    >
                                        {field.type === 'boolean' && (
                                            <Control
                                                field={field}
                                                value={value}
                                                onChange={(next) =>
                                                    change(field.key, next)
                                                }
                                            />
                                        )}
                                        <Label htmlFor={`setting-${field.key}`}>
                                            {field.label}
                                        </Label>
                                        {field.type !== 'boolean' && (
                                            <Control
                                                field={field}
                                                value={value}
                                                onChange={(next) =>
                                                    change(field.key, next)
                                                }
                                            />
                                        )}
                                    </div>
                                    <p className="text-sm text-muted-foreground">
                                        {field.help}
                                        {changed && (
                                            <span data-test="default-note">
                                                {' '}
                                                Default:{' '}
                                                {describeValue(
                                                    field,
                                                    field.default,
                                                )}
                                                .
                                            </span>
                                        )}
                                    </p>
                                    <InputError message={errorFor(field.key)} />
                                </div>
                            );
                        })}

                        {group.name === 'Schedule' && (
                            <p className="text-sm text-muted-foreground">
                                Times are in the shop&apos;s time zone (
                                {time_zone}). A change takes effect from the
                                next scheduled time.
                            </p>
                        )}
                    </fieldset>
                ))}

                <div className="flex flex-wrap items-center gap-3">
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="save-settings"
                    >
                        {form.processing && <Spinner />}
                        Save settings
                    </Button>
                    {has_changes && (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmingReset(true)}
                            data-test="restore-defaults"
                        >
                            Restore defaults
                        </Button>
                    )}
                </div>
            </form>

            <ConfirmDialog
                open={confirmingReset}
                onOpenChange={setConfirmingReset}
                title="Restore the default settings?"
                description="Every setting on this page goes back to its default. Recommendations and forecasts already made are not changed."
                confirmLabel="Restore defaults"
                testId="confirm-restore-defaults"
                onConfirm={() => {
                    setConfirmingReset(false);
                    router.post(reset.url(), {}, { preserveScroll: true });
                }}
            />
        </>
    );
}
