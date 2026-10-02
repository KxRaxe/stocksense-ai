<?php

namespace App\Services\Settings;

use Illuminate\Validation\Rule;

/**
 * One setting an Owner can change: what it is called, how it is checked, and
 * which config value it overrides.
 */
final class SettingDefinition
{
    public const INTEGER = 'integer';

    public const NUMBER = 'number';

    public const BOOLEAN = 'boolean';

    public const TIME = 'time';

    public const CHOICE = 'choice';

    /**
     * @param  string  $key  What the form and the settings table call it (no dots)
     * @param  string  $config  The config value it overrides, e.g. "replenishment.review_days"
     * @param  array<int|string, string>  $choices  For a choice: value => label
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly string $label,
        public readonly string $help,
        public readonly string $type,
        public readonly string $config,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly ?string $unit = null,
        public readonly array $choices = [],
    ) {}

    /**
     * Turns what came from the form, the database or a config file into the type this setting holds.
     */
    public function cast(mixed $value): int|float|bool|string
    {
        return match ($this->type) {
            self::INTEGER, self::CHOICE => (int) $value,
            self::NUMBER => round((float) $value, 2),
            self::BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this->type) {
            self::INTEGER => ['required', 'integer', 'min:'.$this->min, 'max:'.$this->max],
            self::NUMBER => ['required', 'numeric', 'min:'.$this->min, 'max:'.$this->max],
            self::BOOLEAN => ['required', 'boolean'],
            self::TIME => ['required', 'date_format:H:i'],
            self::CHOICE => ['required', 'integer', Rule::in(array_keys($this->choices))],
            default => ['required'],
        };
    }
}
