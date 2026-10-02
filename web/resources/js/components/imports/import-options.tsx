import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { ImportOption } from '@/types';

type Props = {
    options: ImportOption[];
    /** The current value of each option, as text. Without it, the first choice is selected. */
    values?: Record<string, string>;
    errors?: Record<string, string | undefined>;
    /** Leave out the explanations under each choice. */
    compact?: boolean;
};

/**
 * The choices an import asks for, drawn from the server's descriptions so each
 * kind of import can ask its own questions. Fields are named after the option,
 * so they post as ordinary form values.
 */
export default function ImportOptions({
    options,
    values,
    errors = {},
    compact = false,
}: Props) {
    return (
        <>
            {options.map((option) => {
                const value = values?.[option.name];

                if (option.kind === 'select') {
                    return (
                        <div key={option.name} className="grid gap-2">
                            <Label htmlFor={option.name}>{option.label}</Label>
                            <NativeSelect
                                id={option.name}
                                name={option.name}
                                defaultValue={
                                    value ?? option.choices[0]?.value ?? ''
                                }
                            >
                                {option.choices.map((choice) => (
                                    <option
                                        key={choice.value}
                                        value={choice.value}
                                    >
                                        {choice.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors[option.name]} />
                        </div>
                    );
                }

                if (option.kind === 'radio') {
                    const selected = value ?? option.choices[0]?.value;

                    return (
                        <fieldset key={option.name} className="grid gap-3">
                            <legend className="mb-1 text-sm font-medium">
                                {option.label}
                            </legend>
                            {option.choices.map((choice) => (
                                <div
                                    key={choice.value}
                                    className="flex items-start gap-3 text-sm"
                                >
                                    <input
                                        type="radio"
                                        id={`${option.name}-${choice.value}`}
                                        name={option.name}
                                        value={choice.value}
                                        defaultChecked={
                                            choice.value === selected
                                        }
                                        aria-describedby={
                                            choice.help && !compact
                                                ? `${option.name}-${choice.value}-help`
                                                : undefined
                                        }
                                        className="mt-1"
                                    />
                                    <div>
                                        <label
                                            htmlFor={`${option.name}-${choice.value}`}
                                            className="font-medium"
                                        >
                                            {choice.label}
                                        </label>
                                        {choice.help && !compact && (
                                            <p
                                                id={`${option.name}-${choice.value}-help`}
                                                className="text-muted-foreground"
                                            >
                                                {choice.help}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                            <InputError message={errors[option.name]} />
                        </fieldset>
                    );
                }

                // A tick box. An unticked box sends nothing, so a hidden "0" goes
                // first and the ticked box's "1" replaces it.
                return (
                    <fieldset key={option.name} className="grid gap-3">
                        <legend className="mb-1 text-sm font-medium">
                            {option.label}
                        </legend>
                        <input type="hidden" name={option.name} value="0" />
                        {option.choices.map((choice) => (
                            <div
                                key={choice.value}
                                className="flex items-start gap-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    id={`${option.name}-${choice.value}`}
                                    name={option.name}
                                    value={choice.value}
                                    defaultChecked={value === choice.value}
                                    aria-describedby={
                                        choice.help && !compact
                                            ? `${option.name}-${choice.value}-help`
                                            : undefined
                                    }
                                    className="mt-1"
                                />
                                <div>
                                    <label
                                        htmlFor={`${option.name}-${choice.value}`}
                                        className="font-medium"
                                    >
                                        {choice.label}
                                    </label>
                                    {choice.help && !compact && (
                                        <p
                                            id={`${option.name}-${choice.value}-help`}
                                            className="text-muted-foreground"
                                        >
                                            {choice.help}
                                        </p>
                                    )}
                                </div>
                            </div>
                        ))}
                        <InputError message={errors[option.name]} />
                    </fieldset>
                );
            })}
        </>
    );
}
