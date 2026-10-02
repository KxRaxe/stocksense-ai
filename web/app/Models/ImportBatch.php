<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded file and what became of it.
 *
 * `settings` holds the file's header names, which column is which
 * (`columns`: field name => 0-based column number or null) and the choices the
 * person made for this kind of import (see ImportDefinition::options()). Sales
 * imports add `date_format` and `adjust_stock`; product imports add
 * `existing_skus` and `create_categories`.
 *
 * `rows_ok` counts the rows that took effect. For product imports, the ones
 * that updated an existing product are also counted in `rows_updated`.
 *
 * @property int $id
 * @property ImportType $type
 * @property string $filename
 * @property ImportStatus $status
 * @property array{headers: list<string>, columns: array<string, int|null>, ...<string, mixed>} $settings
 * @property int $rows_total
 * @property int $rows_processed
 * @property int $rows_ok
 * @property int $rows_duplicate
 * @property int $rows_updated
 * @property int $rows_failed
 * @property list<array{row: int, messages: list<string>}>|null $errors
 * @property string|null $error_message
 * @property int|null $user_id
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'type', 'filename', 'status', 'settings', 'rows_total', 'rows_processed',
    'rows_ok', 'rows_duplicate', 'rows_updated', 'rows_failed', 'errors', 'error_message',
    'user_id', 'started_at', 'finished_at',
])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ImportType::class,
            'status' => ImportStatus::class,
            'settings' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * One of the choices made for this import (see ImportDefinition::options()),
     * or null if there is none by that name.
     */
    public function option(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * Folder (on the imports disk) holding this batch's rows and error files.
     */
    public function directory(): string
    {
        return "imports/{$this->id}";
    }

    public function rowsPath(): string
    {
        return $this->directory().'/rows.jsonl';
    }

    public function errorsPath(): string
    {
        return $this->directory().'/errors.jsonl';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Sale, $this>
     */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /**
     * The products a product import created.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
