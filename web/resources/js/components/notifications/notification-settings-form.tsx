import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { firstError } from '@/lib/errors';
import { update } from '@/routes/notification-preferences';
import type {
    DigestFrequency,
    FrequencyOption,
    NotificationSetting,
} from '@/types';

type Choice = {
    mail: boolean;
    database: boolean;
    digest: DigestFrequency | null;
};

type Props = {
    types: NotificationSetting[];
    frequencies: FrequencyOption[];
};

/**
 * One block per kind of notification the person could receive: whether it
 * shows in the app, whether it is emailed, and for the digest, how often.
 * Saved together with one button.
 */
export default function NotificationSettingsForm({
    types,
    frequencies,
}: Props) {
    const form = useForm<{ settings: Record<string, Choice> }>({
        settings: Object.fromEntries(
            types.map((type) => [
                type.type,
                {
                    mail: type.mail,
                    database: type.database,
                    digest: type.digest,
                },
            ]),
        ),
    });

    const change = (type: string, patch: Partial<Choice>) =>
        form.setData('settings', {
            ...form.data.settings,
            [type]: { ...form.data.settings[type], ...patch },
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(update.url(), { preserveScroll: true });
    };

    const error =
        Object.keys(form.errors).length > 0 ? firstError(form.errors) : null;

    return (
        <form onSubmit={submit} className="space-y-6">
            {error && (
                <p
                    className="text-sm text-destructive"
                    role="alert"
                    data-test="settings-error"
                >
                    {error}
                </p>
            )}

            {types.map((type) => {
                const choice = form.data.settings[type.type];

                return (
                    <fieldset
                        key={type.type}
                        className="space-y-3 rounded-xl border-2 bg-card p-4 shadow-brutal"
                        data-test={`setting-${type.type}`}
                    >
                        <legend className="px-1 text-sm font-medium">
                            {type.label}
                        </legend>
                        <p className="text-sm text-muted-foreground">
                            {type.description}
                        </p>

                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`${type.type}-database`}
                                    checked={choice.database}
                                    onCheckedChange={(checked) =>
                                        change(type.type, {
                                            database: checked === true,
                                        })
                                    }
                                    data-test={`${type.type}-database`}
                                />
                                <Label htmlFor={`${type.type}-database`}>
                                    In the app
                                </Label>
                            </div>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id={`${type.type}-mail`}
                                    checked={choice.mail}
                                    onCheckedChange={(checked) =>
                                        change(type.type, {
                                            mail: checked === true,
                                        })
                                    }
                                    data-test={`${type.type}-mail`}
                                />
                                <Label htmlFor={`${type.type}-mail`}>
                                    By email
                                </Label>
                            </div>
                        </div>

                        {type.is_digest && (
                            <div className="grid gap-2 sm:max-w-56">
                                <Label htmlFor={`${type.type}-digest`}>
                                    How often
                                </Label>
                                <NativeSelect
                                    id={`${type.type}-digest`}
                                    value={choice.digest ?? 'weekly'}
                                    onChange={(event) =>
                                        change(type.type, {
                                            digest: event.target
                                                .value as DigestFrequency,
                                        })
                                    }
                                    data-test={`${type.type}-digest`}
                                >
                                    {frequencies.map((frequency) => (
                                        <option
                                            key={frequency.value}
                                            value={frequency.value}
                                        >
                                            {frequency.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError
                                    message={
                                        form.errors[
                                            `settings.${type.type}.digest` as keyof typeof form.errors
                                        ]
                                    }
                                />
                            </div>
                        )}
                    </fieldset>
                );
            })}

            <p className="text-sm text-muted-foreground">
                Emails never contain your name or email address, only product
                and stock figures and a link back here.
            </p>

            <Button
                type="submit"
                disabled={form.processing}
                data-test="save-settings"
            >
                {form.processing && <Spinner />}
                Save
            </Button>
        </form>
    );
}
