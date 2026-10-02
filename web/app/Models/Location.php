<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A place that holds stock. Exactly one exists today (the default), created by
 * the locations migration.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property bool $is_default
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'code', 'is_default'])]
class Location extends Model
{
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public static function defaultLocation(): self
    {
        return static::query()->where('is_default', true)->firstOrFail();
    }
}
