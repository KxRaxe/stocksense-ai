<?php

namespace App\Services\Reporting;

use App\Models\Recommendation;
use App\Services\Settings\SettingsSchema;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * An audit log entry as a person reads it: when, who, in which area, what
 * happened, to what, and what changed from what to what.
 *
 * Nothing secret is ever shown: values are limited in length, and any value
 * whose name suggests a password, token or key is left out.
 */
class AuditPresenter
{
    private const AREAS = [
        'auth' => 'Sign-ins',
        'catalog' => 'Products and categories',
        'users' => 'Users',
        'sales' => 'Sales',
        'products' => 'Product imports',
        'forecasts' => 'Forecasts',
        'replenishment' => 'Recommendations',
        'settings' => 'System settings',
        'reports' => 'Reports',
        'default' => 'Other',
    ];

    private const HIDDEN = ['password', 'token', 'secret', 'key', 'recovery', 'two_factor'];

    private const MAX_LENGTH = 120;

    /** Numbers that identify a row mean nothing to a person; the subject line says what it was. */
    private const ID_SUFFIX = '_id';

    public static function areaLabel(?string $area): string
    {
        return self::AREAS[$area ?? 'default'] ?? Str::headline((string) $area);
    }

    /**
     * @return array{id: int, area: string, area_label: string, description: string, who: string|null, subject: string|null, details: list<array{label: string, from: string|null, to: string|null}>, created_at: string}
     */
    public function present(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'area' => (string) ($activity->log_name ?? 'default'),
            'area_label' => self::areaLabel($activity->log_name),
            'description' => $activity->description,
            'who' => $activity->causer?->getAttribute('name'),
            'subject' => $this->subject($activity),
            'details' => $this->details($activity),
            'created_at' => $activity->created_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * "Product: Nails", or "Product #3" when it has since been deleted or has no name.
     */
    private function subject(Activity $activity): ?string
    {
        if ($activity->subject_type === null) {
            return null;
        }

        $subject = $activity->subject;

        if ($subject instanceof Recommendation) {
            return 'Recommendation for '.$subject->product->name;
        }

        $kind = Str::headline(class_basename($activity->subject_type));
        $name = $subject?->getAttribute('name') ?? $subject?->getAttribute('sku') ?? $subject?->getAttribute('filename');

        return $name !== null ? "{$kind}: {$name}" : "{$kind} #{$activity->subject_id}";
    }

    /**
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    private function details(Activity $activity): array
    {
        $details = [];

        // What a model's own change tracking recorded: new values, and the old ones when it was an edit.
        $new = $activity->attribute_changes['attributes'] ?? [];
        $old = $activity->attribute_changes['old'] ?? [];

        foreach ($new as $key => $value) {
            if (! $this->visible((string) $key)) {
                continue;
            }

            $details[] = ['label' => Str::headline((string) $key), 'from' => array_key_exists($key, $old) ? $this->text($old[$key]) : null, 'to' => $this->text($value)];
        }

        foreach ($activity->properties ?? [] as $key => $value) {
            if (! $this->visible((string) $key)) {
                continue;
            }

            if ($key === 'changes' && is_array($value)) {
                array_push($details, ...$this->settingChanges($value));

                continue;
            }

            $details[] = ['label' => Str::headline((string) $key), 'from' => null, 'to' => $this->text($value)];
        }

        return $details;
    }

    /**
     * The system settings, which were changed from one value to another.
     *
     * @param  array<string, mixed>  $changes
     * @return list<array{label: string, from: string|null, to: string|null}>
     */
    private function settingChanges(array $changes): array
    {
        $definitions = SettingsSchema::definitions();
        $details = [];

        foreach ($changes as $key => $change) {
            if (! is_array($change)) {
                continue;
            }

            $details[] = [
                'label' => isset($definitions[$key]) ? $definitions[$key]->label : Str::headline((string) $key),
                'from' => $this->text($change['old'] ?? null),
                'to' => $this->text($change['new'] ?? null),
            ];
        }

        return $details;
    }

    private function visible(string $key): bool
    {
        $key = mb_strtolower($key);

        if (str_ends_with($key, self::ID_SUFFIX)) {
            return false;
        }

        foreach (self::HIDDEN as $hidden) {
            if (str_contains($key, $hidden)) {
                return false;
            }
        }

        return true;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            is_scalar($value) => (string) $value,
            is_array($value) => $this->describeArray($value),
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };

        return mb_strlen($text) > self::MAX_LENGTH ? mb_substr($text, 0, self::MAX_LENGTH - 1).'…' : $text;
    }

    /**
     * A list as "a, b" and a map as "from: 2026-09-01, to: 2026-09-30"; anything deeper as JSON.
     *
     * @param  array<mixed>  $value
     */
    private function describeArray(array $value): string
    {
        if (array_filter($value, fn ($item) => ! is_scalar($item) && $item !== null) !== []) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return implode(', ', array_map(
            fn ($item, $key) => (array_is_list($value) ? '' : "{$key}: ").(is_bool($item) ? ($item ? 'Yes' : 'No') : (string) $item),
            $value,
            array_keys($value),
        ));
    }
}
