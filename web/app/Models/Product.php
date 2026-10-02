<?php

namespace App\Models;

use App\Enums\StockStatus;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $sku
 * @property string $name
 * @property int $category_id
 * @property string $unit
 * @property numeric-string $unit_cost
 * @property numeric-string $unit_price
 * @property int $lead_time_days
 * @property int $moq Minimum order quantity
 * @property int $pack_size Orders come in multiples of this
 * @property int|null $reorder_point_override
 * @property int|null $safety_stock_override
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read int|null $stock_on_hand Present after scopeWithStockAt()
 * @property-read int|null $stock_on_order Present after scopeWithStockAt()
 */
#[Fillable([
    'sku', 'name', 'category_id', 'unit', 'unit_cost', 'unit_price',
    'lead_time_days', 'moq', 'pack_size',
    'reorder_point_override', 'safety_stock_override', 'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'lead_time_days' => 'integer',
            'moq' => 'integer',
            'pack_size' => 'integer',
            'reorder_point_override' => 'integer',
            'safety_stock_override' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * SKUs are stored trimmed and in upper case so "ab-1" and "AB-1" are the
     * same product.
     *
     * @return Attribute<string, string>
     */
    protected function sku(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => Str::upper(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<InventoryLevel, $this>
     */
    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Adds `stock_on_hand` and `stock_on_order` for one location (0 when the
     * product has no stock record there yet), without an extra query per row.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithStockAt(Builder $query, int $locationId): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }

        $level = 'select %s from inventory_levels where inventory_levels.product_id = products.id and inventory_levels.location_id = ?';

        $query->selectRaw('coalesce(('.sprintf($level, 'on_hand').'), 0) as stock_on_hand', [$locationId])
            ->selectRaw('coalesce(('.sprintf($level, 'on_order').'), 0) as stock_on_order', [$locationId]);
    }

    /**
     * Keeps only products in a given stock status at a location.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInStockStatus(Builder $query, StockStatus $status, int $locationId): void
    {
        $onHand = '(select coalesce(sum(on_hand), 0) from inventory_levels where inventory_levels.product_id = products.id and inventory_levels.location_id = ?)';

        match ($status) {
            StockStatus::OutOfStock => $query->whereRaw("{$onHand} <= 0", [$locationId]),
            StockStatus::Low => $query->whereRaw(
                "{$onHand} > 0 and products.reorder_point_override is not null and {$onHand} <= products.reorder_point_override",
                [$locationId, $locationId],
            ),
            StockStatus::Ok => $query->whereRaw(
                "{$onHand} > 0 and (products.reorder_point_override is null or {$onHand} > products.reorder_point_override)",
                [$locationId, $locationId],
            ),
        };
    }

    /**
     * Case-insensitive match on name or SKU.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        // Escape LIKE wildcards so a search for "50%" means the literal text.
        $like = '%'.addcslashes($term, '\\%_').'%';

        $query->where(fn (Builder $q) => $q
            ->where('products.name', 'ilike', $like)
            ->orWhere('products.sku', 'ilike', $like));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('catalog')
            ->logOnly([
                'sku', 'name', 'category_id', 'unit', 'unit_cost', 'unit_price',
                'lead_time_days', 'moq', 'pack_size',
                'reorder_point_override', 'safety_stock_override', 'is_active',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
