<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded sales file and what became of it.
 *
 * `settings` holds the file's header names, which column is which
 * (`columns`: date, sku, quantity, unit_price, each a 0-based column number or
 * null), the date format, and whether stock levels are adjusted.
 *
 * @property int $id
 * @property string $type
 * @property string $filename
 * @property ImportStatus $status
 * @property array{headers: list<string>, columns: array<string, int|null>, date_format: string, adjust_stock: bool} $settings
 * @property int $rows_total
 * @property int $rows_processed
 * @property int $rows_ok
 * @property int $rows_duplicate
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
    'rows_ok', 'rows_duplicate', 'rows_failed', 'errors', 'error_message',
    'user_id', 'started_at', 'finished_at',
])]
class ImportBatch extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'settings' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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
}
