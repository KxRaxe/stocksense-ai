<?php

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->manager = User::factory()->manager()->create();
    $this->category = Category::factory()->create(['name' => 'Hardware']);
});

/** A valid product payload with the given fields replaced. */
function productPayload(Category $category, array $overrides = []): array
{
    return array_merge([
        'sku' => 'hw-001',
        'name' => 'Common wire nails',
        'category_id' => $category->id,
        'unit' => 'kg',
        'unit_cost' => '62.00',
        'unit_price' => '85.50',
        'lead_time_days' => 14,
        'moq' => 5,
        'pack_size' => 5,
        'reorder_point_override' => 20,
        'safety_stock_override' => null,
    ], $overrides);
}

describe('creating', function () {
    it('saves the product with an upper-case SKU and logs who created it', function () {
        $response = $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category))
            ->assertSessionHasNoErrors();

        $product = Product::firstOrFail();
        $response->assertRedirect(route('products.show', $product));

        expect($product->sku)->toBe('HW-001')
            ->and($product->unit_price)->toBe('85.50')
            ->and($product->pack_size)->toBe(5)
            ->and($product->safety_stock_override)->toBeNull()
            ->and($product->is_active)->toBeTrue();

        // (The category made in beforeEach also logged a "created" entry, with no user.)
        $activity = Activity::where('log_name', 'catalog')
            ->where('event', 'created')
            ->where('subject_type', $product->getMorphClass())
            ->firstOrFail();
        expect($activity->causer_id)->toBe($this->manager->id);
    });

    it('records opening stock in the ledger and the stock level', function () {
        $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category, ['opening_stock' => 40]));

        $product = Product::firstOrFail();
        $movement = StockMovement::firstOrFail();

        expect($movement->type)->toBe(StockMovementType::Initial)
            ->and($movement->quantity)->toBe(40)
            ->and($movement->user_id)->toBe($this->manager->id)
            ->and($movement->location_id)->toBe(Location::defaultLocation()->id)
            ->and(InventoryLevel::where('product_id', $product->id)->value('on_hand'))->toBe(40);
    });

    it('writes no stock rows when there is no opening stock', function (mixed $opening) {
        $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category, ['opening_stock' => $opening]));

        expect(Product::count())->toBe(1)
            ->and(StockMovement::count())->toBe(0)
            ->and(InventoryLevel::count())->toBe(0);
    })->with(['blank' => [''], 'zero' => [0], 'omitted' => [null]]);

    it('does not create a product if it cannot be saved with its stock', function () {
        $this->mock(StockService::class)
            ->shouldReceive('openingStock')->andThrow(new RuntimeException('boom'));

        $this->withoutExceptionHandling()
            ->actingAs($this->manager);

        expect(fn () => $this->post(route('products.store'), productPayload($this->category, ['opening_stock' => 5])))
            ->toThrow(RuntimeException::class);

        expect(Product::count())->toBe(0);
    });
});

describe('validation', function () {
    it('rejects a SKU that is already used, ignoring capitals and spaces', function () {
        Product::factory()->for($this->category)->create(['sku' => 'HW-001']);

        $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category, ['sku' => '  hw-001 ']))
            ->assertSessionHasErrors('sku');

        expect(Product::count())->toBe(1);
    });

    it('rejects invalid values', function (string $field, mixed $value) {
        $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category, [$field => $value]))
            ->assertSessionHasErrors($field);

        expect(Product::count())->toBe(0);
    })->with([
        'missing name' => ['name', ''],
        'unknown category' => ['category_id', 9999],
        'negative cost' => ['unit_cost', '-1'],
        'price not a number' => ['unit_price', 'free'],
        'lead time too long' => ['lead_time_days', 400],
        'negative lead time' => ['lead_time_days', -1],
        'minimum order of zero' => ['moq', 0],
        'pack size of zero' => ['pack_size', 0],
        'fractional pack size' => ['pack_size', 1.5],
        'negative reorder point' => ['reorder_point_override', -5],
        'negative opening stock' => ['opening_stock', -1],
        'fractional opening stock' => ['opening_stock', 2.5],
    ]);

    it('accepts an empty reorder point and safety stock', function () {
        $this->actingAs($this->manager)
            ->post(route('products.store'), productPayload($this->category, [
                'reorder_point_override' => '',
                'safety_stock_override' => '',
            ]))
            ->assertSessionHasNoErrors();

        expect(Product::firstOrFail()->reorder_point_override)->toBeNull();
    });
});

describe('editing', function () {
    it('updates the product and logs only what changed', function () {
        $product = Product::factory()->for($this->category)->create(['sku' => 'HW-001', 'name' => 'Old name']);
        Activity::query()->delete();

        $this->actingAs($this->manager)
            ->put(route('products.update', $product), productPayload($this->category, ['name' => 'New name']))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHasNoErrors();

        expect($product->fresh()->name)->toBe('New name');

        $changes = Activity::where('event', 'updated')->firstOrFail()->attribute_changes;
        expect($changes['attributes']['name'])->toBe('New name')
            ->and($changes['old']['name'])->toBe('Old name');
    });

    it('keeps its own SKU without a clash', function () {
        $product = Product::factory()->for($this->category)->create(['sku' => 'HW-001']);

        $this->actingAs($this->manager)
            ->put(route('products.update', $product), productPayload($this->category))
            ->assertSessionHasNoErrors();
    });

    it('cannot change stock; that goes through restocks and stock-takes', function () {
        $product = Product::factory()->for($this->category)->create(['sku' => 'HW-001']);
        app(StockService::class)->openingStock($product, 10);

        $this->actingAs($this->manager)
            ->put(route('products.update', $product), productPayload($this->category, ['opening_stock' => 999]));

        expect(InventoryLevel::where('product_id', $product->id)->value('on_hand'))->toBe(10)
            ->and(StockMovement::count())->toBe(1);
    });
});

describe('archiving', function () {
    it('archives and restores without losing history', function () {
        $product = Product::factory()->for($this->category)->create();
        app(StockService::class)->openingStock($product, 10);

        $this->actingAs($this->manager)->patch(route('products.archive', $product))->assertRedirect();
        expect($product->fresh()->is_active)->toBeFalse()
            ->and(StockMovement::count())->toBe(1);

        $this->actingAs($this->manager)->patch(route('products.restore', $product))->assertRedirect();
        expect($product->fresh()->is_active)->toBeTrue();
    });
});

describe('the list', function () {
    beforeEach(function () {
        $this->nails = Product::factory()->for($this->category)->create(['sku' => 'HW-001', 'name' => 'Wire nails']);
        $this->cement = Product::factory()->for($this->category)->create(['sku' => 'HW-002', 'name' => 'Cement 40kg']);
        $this->soap = Product::factory()->for(Category::factory())->create(['sku' => 'PC-001', 'name' => 'Bath soap 100%']);
        $this->old = Product::factory()->archived()->for($this->category)->create(['sku' => 'HW-009', 'name' => 'Old stock']);
    });

    /** IDs of the products the list page returned. */
    function listedIds($response): array
    {
        return collect($response->viewData('page')['props']['products']['data'])->pluck('id')->sort()->values()->all();
    }

    it('shows active products by default', function () {
        $ids = listedIds($this->actingAs($this->manager)->get(route('products.index')));

        expect($ids)->toBe(collect([$this->nails, $this->cement, $this->soap])->pluck('id')->sort()->values()->all());
    });

    it('can show archived products, or all', function (string $status, int $count) {
        $response = $this->actingAs($this->manager)->get(route('products.index', ['status' => $status]));

        expect(listedIds($response))->toHaveCount($count);
    })->with(['archived' => ['archived', 1], 'all' => ['all', 4]]);

    it('searches name and SKU regardless of capitals', function (string $term, array $expected) {
        $response = $this->actingAs($this->manager)->get(route('products.index', ['search' => $term]));

        expect(listedIds($response))->toBe(collect($expected)->map(fn ($name) => $this->{$name}->id)->sort()->values()->all());
    })->with([
        'by name' => ['WIRE', ['nails']],
        'by sku' => ['pc-0', ['soap']],
        'matches several' => ['hw-00', ['nails', 'cement']],
        'no match' => ['zzz', []],
    ]);

    it('treats a percent sign in the search as plain text', function () {
        $response = $this->actingAs($this->manager)->get(route('products.index', ['search' => '100%']));

        expect(listedIds($response))->toBe([$this->soap->id]);
    });

    it('filters by category', function () {
        $response = $this->actingAs($this->manager)->get(route('products.index', ['category' => $this->category->id]));

        expect(listedIds($response))->toBe(collect([$this->nails, $this->cement])->pluck('id')->sort()->values()->all());
    });

    it('pages the results', function () {
        Product::factory()->count(20)->for($this->category)->create();

        $this->actingAs($this->manager)
            ->get(route('products.index'))
            ->assertInertia(fn ($page) => $page
                ->has('products.data', 15)
                ->where('products.total', 23)
                ->where('products.last_page', 2));
    });
});

describe('the product page', function () {
    it('shows stock and the most recent movements first', function () {
        $product = Product::factory()->for($this->category)->reorderAt(10)->create();
        $stock = app(StockService::class);
        $stock->openingStock($product, 50, $this->manager);
        $stock->restock($product, 5, note: 'Delivery');
        $stock->adjustTo($product, 8, 'Count');

        $this->actingAs($this->manager)
            ->get(route('products.show', $product))
            ->assertInertia(fn ($page) => $page
                ->component('products/show')
                ->where('stock.on_hand', 8)
                ->where('stock.status', 'low')
                ->where('movements.0.type', 'adjustment')
                ->where('movements.0.quantity', -47)
                ->where('movements.2.type', 'initial')
                ->where('movements.2.user', $this->manager->name)
                ->where('can.manage', true)
                ->where('can.adjust', true));
    });

    it('shows zero for a product that has no stock record, without creating one', function () {
        $product = Product::factory()->for($this->category)->create();

        $this->actingAs($this->manager)
            ->get(route('products.show', $product))
            ->assertInertia(fn ($page) => $page->where('stock.on_hand', 0)->where('stock.status', 'out_of_stock'));

        expect(InventoryLevel::count())->toBe(0);
    });

    it('only shows movements for the current location', function () {
        $product = Product::factory()->for($this->category)->create();
        $branch = Location::create(['name' => 'Branch', 'code' => 'BR2']);

        $stock = app(StockService::class);
        $stock->openingStock($product, 10);
        $stock->record($product, StockMovementType::Restock, 99, location: $branch);

        $this->actingAs($this->manager)
            ->get(route('products.show', $product))
            ->assertInertia(fn ($page) => $page->where('stock.on_hand', 10)->has('movements', 1));
    });

    it('lets staff see it but not manage the product', function () {
        $product = Product::factory()->for($this->category)->create();

        $this->actingAs(User::factory()->inventoryStaff()->create())
            ->get(route('products.show', $product))
            ->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.adjust', true));
    });
});
