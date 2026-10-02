import type { SettingField, SettingValue } from '@/types';

/** A setting's value in words: "7 days", "On", "Monday", "06:00". */
export function describeValue(
    field: SettingField,
    value: SettingValue = field.value,
): string {
    switch (field.type) {
        case 'boolean':
            return value === true ? 'On' : 'Off';
        case 'choice':
            return (
                field.choices.find(
                    (choice) => String(choice.value) === String(value),
                )?.label ?? String(value)
            );
        default:
            return field.unit ? `${value} ${field.unit}` : String(value);
    }
}

/** The step for a number input: whole numbers move by 1, others by 0.1. */
export function stepFor(field: SettingField): string {
    return field.type === 'integer' ? '1' : '0.1';
}
