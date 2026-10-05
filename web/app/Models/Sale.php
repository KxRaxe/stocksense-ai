<?php

namespace App\Models;

use App\Enums\SaleSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of sales: a product sold on a day. These rows are what the
 * forecasts learn from.
 *
 * @property int $id
 * @property int $product_id
 * @property int $location_id
 * @property CarbonInterface $sold_on
 * @property int $quantity
 * @property string $unit_price
 * @property string $total
 * @property SaleSource $source
 * @property int|null $import_batch_id
 * @property int|null $user_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['product_id', 'location_id', 'sold_on', 'quantity', 'unit_price', 'total', 'source', 'import_batch_id', 'user_id'])]
class Sale extends Model
{
    protected function casts(): array
    {
        return [
            'sold_on' => 'date',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'source' => SaleSource::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
