<?php

use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockService;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->user = User::factory()->inventoryStaff()->create();
    $stock = app(StockService::class);

    // One product in each stock state.
    $this->none = Product::factory()->reorderAt(10)->create(['name' => 'A none']);                 // no stock record
    $this->low = Product::factory()->reorderAt(10)->create(['name' => 'B low']);
    $stock->openingStock($this->low, 5);
    $this->ok = Product::factory()->reorderAt(10)->create(['name' => 'C ok']);
    $stock->openingStock($this->ok, 50);
    $this->unset = Product::factory()->create(['name' => 'D unset']);                               // stock but no reorder point
    $stock->openingStock($this->unset, 2);
    $this->archived = Product::factory()->archived()->create(['name' => 'E archived']);
    $stock->openingStock($this->archived, 7);
});

/** The rows the overview returned, keyed by product name. */
function overview(array $query = []): array
{
    $response = test()->actingAs(test()->user)->get(route('inventory.index', $query));

    return collect($response->viewData('page')['props']['products']['data'])->keyBy('name')->all();
}

it('shows on hand, status and reorder details for active products', function () {
    $rows = overview();

    expect(array_keys($rows))->toBe(['A none', 'B low', 'C ok', 'D unset'])
        ->and($rows['A none'])->toMatchArray(['on_hand' => 0, 'status' => 'out_of_stock', 'reorder_point' => 10])
        ->and($rows['B low'])->toMatchArray(['on_hand' => 5, 'status' => 'low'])
        ->and($rows['C ok'])->toMatchArray(['on_hand' => 50, 'status' => 'ok'])
        ->and($rows['D unset'])->toMatchArray(['on_hand' => 2, 'status' => 'ok', 'reorder_point' => null]);
});

it('filters by stock status', function (string $stock, array $expected) {
    expect(array_keys(overview(['stock' => $stock])))->toBe($expected);
})->with([
    'out of stock' => ['out_of_stock', ['A none']],
    'low' => ['low', ['B low']],
    'in stock' => ['ok', ['C ok', 'D unset']],
    'unknown value shows everything' => ['bogus', ['A none', 'B low', 'C ok', 'D unset']],
]);

it('combines the stock filter with search and category', function () {
    $other = Category::factory()->create();
    $this->low->update(['category_id' => $other->id]);

    expect(array_keys(overview(['category' => $other->id])))->toBe(['B low'])
        ->and(array_keys(overview(['stock' => 'ok', 'search' => 'unset'])))->toBe(['D unset'])
        ->and(overview(['stock' => 'low', 'search' => 'ok']))->toBe([]);
});

it('counts only stock at the current location', function () {
    $branch = Location::create(['name' => 'Branch', 'code' => 'BR2']);
    app(StockService::class)->record($this->none, StockMovementType::Restock, 500, location: $branch);

    expect(overview()['A none'])->toMatchArray(['on_hand' => 0, 'status' => 'out_of_stock']);
});

it('lets every role open it', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create())
        ->get(route('inventory.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inventory/index')->where('can.adjust', true));
})->with(['owner', 'manager', 'inventoryStaff']);
