<?php

use App\Enums\Role;
use App\Enums\SaleSource;
use App\Models\Category;
use App\Models\InventoryLevel;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Services\Sales\SalesService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->staff = User::factory()->inventoryStaff()->create();
    $category = Category::factory()->create();

    $this->nails = Product::factory()->for($category)->create(['sku' => 'HW-001', 'name' => 'Wire nails', 'unit_price' => 85]);
    $this->cement = Product::factory()->for($category)->create(['sku' => 'HW-002', 'name' => 'Cement', 'unit_price' => 275]);

    $stock = app(StockService::class);
    $stock->openingStock($this->nails, 100);
    $stock->openingStock($this->cement, 40);
});

/** A valid day's sheet with the given overrides. */
function salesSheet(array $overrides = []): array
{
    return array_merge([
        'sold_on' => CarbonImmutable::today()->toDateString(),
        'items' => [
            ['product_id' => test()->nails->id, 'quantity' => 12, 'unit_price' => '85.00'],
            ['product_id' => test()->cement->id, 'quantity' => 3, 'unit_price' => '280.00'],
        ],
    ], $overrides);
}

describe('entering sales', function () {
    it('saves every line of the sheet and takes the stock off', function (Role $role) {
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)
            ->post(route('sales.store'), salesSheet())
            ->assertRedirect(route('sales.index'))
            ->assertSessionHasNoErrors();

        expect(Sale::count())->toBe(2)
            ->and(Sale::where('source', 'manual')->count())->toBe(2)
            ->and(Sale::pluck('user_id')->unique()->all())->toBe([$user->id])
            ->and(Sale::where('product_id', $this->cement->id)->first()->total)->toBe('840.00')
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(88)
            ->and(InventoryLevel::where('product_id', $this->cement->id)->value('on_hand'))->toBe(37);
    })->with(Role::cases());

    it('records an earlier date', function () {
        $this->actingAs($this->staff)
            ->post(route('sales.store'), salesSheet(['sold_on' => '2026-03-05']));

        expect(Sale::pluck('sold_on')->map->toDateString()->unique()->all())->toBe(['2026-03-05']);
    });

    it('can list the same product more than once', function () {
        $this->actingAs($this->staff)->post(route('sales.store'), salesSheet([
            'items' => [
                ['product_id' => $this->nails->id, 'quantity' => 2, 'unit_price' => '85.00'],
                ['product_id' => $this->nails->id, 'quantity' => 3, 'unit_price' => '90.00'],
            ],
        ]));

        expect(Sale::count())->toBe(2)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(95);
    });

    it('rejects a bad sheet and saves nothing', function (array $overrides, string $field) {
        $this->actingAs($this->staff)
            ->post(route('sales.store'), salesSheet($overrides))
            ->assertSessionHasErrors($field);

        expect(Sale::count())->toBe(0)
            ->and(StockMovement::where('type', 'sale')->count())->toBe(0);
    })->with([
        'no date' => [['sold_on' => ''], 'sold_on'],
        'future date' => [['sold_on' => '2999-01-01'], 'sold_on'],
        'date before 2000' => [['sold_on' => '1999-12-31'], 'sold_on'],
        'no lines' => [['items' => []], 'items'],
        'unknown product' => [['items' => [['product_id' => 9999, 'quantity' => 1, 'unit_price' => '1']]], 'items.0.product_id'],
        'zero quantity' => [['items' => [['product_id' => 1, 'quantity' => 0, 'unit_price' => '1']]], 'items.0.quantity'],
        'fractional quantity' => [['items' => [['product_id' => 1, 'quantity' => 1.5, 'unit_price' => '1']]], 'items.0.quantity'],
        'negative price' => [['items' => [['product_id' => 1, 'quantity' => 1, 'unit_price' => '-1']]], 'items.0.unit_price'],
        'missing price' => [['items' => [['product_id' => 1, 'quantity' => 1]]], 'items.0.unit_price'],
    ]);

    it('rejects an archived product', function () {
        $this->nails->update(['is_active' => false]);

        $this->actingAs($this->staff)
            ->post(route('sales.store'), salesSheet())
            ->assertSessionHasErrors('items.0.product_id');

        expect(Sale::count())->toBe(0);
    });

    it('rejects a sheet with too many lines', function () {
        $items = array_fill(0, 101, ['product_id' => $this->nails->id, 'quantity' => 1, 'unit_price' => '1']);

        $this->actingAs($this->staff)
            ->post(route('sales.store'), salesSheet(['items' => $items]))
            ->assertSessionHasErrors('items');
    });

    it('saves all of the sheet or none of it', function () {
        // The second line fails after the first has been saved.
        $this->mock(SalesService::class, function ($mock) {
            $mock->shouldReceive('record')->once()->andReturn(new Sale);
            $mock->shouldReceive('record')->once()->andThrow(new RuntimeException('boom'));
        });

        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->staff)->post(route('sales.store'), salesSheet()))
            ->toThrow(RuntimeException::class);
    });

    it('rolls the first line back when the second fails for real', function () {
        // Real service, with a stock failure on the second movement.
        $calls = 0;
        InventoryLevel::saving(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('disk full');
            }
        });

        $this->withoutExceptionHandling();

        try {
            expect(fn () => $this->actingAs($this->staff)->post(route('sales.store'), salesSheet()))
                ->toThrow(RuntimeException::class);
        } finally {
            InventoryLevel::flushEventListeners();
        }

        expect(Sale::count())->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100);
    });
});

describe('the entry page', function () {
    it('offers active products with their price and stock', function () {
        Product::factory()->archived()->create(['name' => 'Retired item']);

        $this->actingAs($this->staff)
            ->get(route('sales.create'))
            ->assertInertia(fn ($page) => $page
                ->component('sales/create')
                ->has('products', 2)
                ->where('products.0.sku', 'HW-002')   // Cement sorts before Wire nails
                ->where('products.0.unit_price', 275)
                ->where('products.0.on_hand', 40)
                ->where('today', CarbonImmutable::today()->toDateString()));
    });
});

describe('deleting a sale', function () {
    it('removes a sale entered by hand and puts the stock back', function () {
        $sale = app(SalesService::class)->record($this->nails, CarbonImmutable::today(), 12, '85.00', SaleSource::Manual, $this->staff);

        $this->actingAs($this->staff)
            ->delete(route('sales.destroy', $sale))
            ->assertRedirect();

        expect(Sale::count())->toBe(0)
            ->and(InventoryLevel::where('product_id', $this->nails->id)->value('on_hand'))->toBe(100);
    });

    it('records who deleted it and what it was', function () {
        $sale = app(SalesService::class)->record($this->nails, CarbonImmutable::parse('2026-03-05'), 12, '85.00', SaleSource::Manual, $this->staff);

        $this->actingAs($this->staff)->delete(route('sales.destroy', $sale));

        $activity = Activity::where('event', 'sale_deleted')->firstOrFail();

        expect($activity->causer_id)->toBe($this->staff->id)
            ->and($activity->getProperty('sku'))->toBe('HW-001')
            ->and($activity->getProperty('quantity'))->toBe(12)
            ->and($activity->getProperty('sold_on'))->toBe('2026-03-05');
    });

    it('will not delete an imported sale', function () {
        $sale = app(SalesService::class)->record($this->nails, CarbonImmutable::today(), 12, '85.00', SaleSource::Import);

        $this->actingAs($this->staff)
            ->delete(route('sales.destroy', $sale))
            ->assertSessionHasErrors('sale');

        expect(Sale::count())->toBe(1);
    });
});

describe('the sales list', function () {
    beforeEach(function () {
        $sales = app(SalesService::class);
        $this->old = $sales->record($this->nails, CarbonImmutable::parse('2026-01-10'), 5, '85.00', SaleSource::Import);
        $this->mid = $sales->record($this->cement, CarbonImmutable::parse('2026-02-15'), 2, '275.00', SaleSource::Manual, $this->staff);
        $this->recent = $sales->record($this->nails, CarbonImmutable::parse('2026-03-20'), 10, '90.00', SaleSource::Manual, $this->staff);
    });

    /** The list page's sale ids, in the order shown, plus its totals. */
    function salesList(array $query = []): array
    {
        $props = test()->actingAs(test()->staff)->get(route('sales.index', $query))->viewData('page')['props'];

        return ['ids' => collect($props['sales']['data'])->pluck('id')->all(), 'totals' => $props['totals']];
    }

    it('shows the newest sales first, with totals', function () {
        $list = salesList();

        expect($list['ids'])->toBe([$this->recent->id, $this->mid->id, $this->old->id])
            ->and($list['totals'])->toBe(['lines' => 3, 'units' => 17, 'revenue' => 5.0 * 85 + 2 * 275 + 10 * 90]);
    });

    it('filters by date range, source and product', function (array $query, array $expected, int $units) {
        $list = salesList($query);

        expect($list['ids'])->toBe(collect($expected)->map(fn ($name) => $this->{$name}->id)->all())
            ->and($list['totals']['units'])->toBe($units);
    })->with([
        'from a date' => [['from' => '2026-02-01'], ['recent', 'mid'], 12],
        'up to a date' => [['to' => '2026-02-28'], ['mid', 'old'], 7],
        'between dates' => [['from' => '2026-02-01', 'to' => '2026-02-28'], ['mid'], 2],
        'entered by hand' => [['source' => 'manual'], ['recent', 'mid'], 12],
        'imported' => [['source' => 'import'], ['old'], 5],
        'one product by name' => [['search' => 'cement'], ['mid'], 2],
        'one product by SKU' => [['search' => 'hw-001'], ['recent', 'old'], 15],
        'nothing matches' => [['search' => 'zzz'], [], 0],
        'a bad date is ignored' => [['from' => 'soon'], ['recent', 'mid', 'old'], 17],
    ]);

    it('only counts sales at the current location', function () {
        $branch = Location::create(['name' => 'Branch', 'code' => 'BR2']);
        Sale::query()->whereKey($this->old->id)->update(['location_id' => $branch->id]);

        expect(salesList()['ids'])->toBe([$this->recent->id, $this->mid->id]);
    });

    it('pages the results', function () {
        $sales = app(SalesService::class);

        foreach (range(1, 30) as $day) {
            $sales->record($this->nails, CarbonImmutable::parse('2026-04-01')->addDays($day % 28), 1, '85.00', SaleSource::Manual, adjustStock: false);
        }

        $this->actingAs($this->staff)
            ->get(route('sales.index'))
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 25)
                ->where('sales.total', 33)
                ->where('sales.last_page', 2));
    });

    it('tells the page what the user may do', function (Role $role, bool $enter, bool $import) {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->get(route('sales.index'))
            ->assertInertia(fn ($page) => $page->where('can.enter', $enter)->where('can.import', $import));
    })->with([
        'owner' => [Role::Owner, true, true],
        'manager' => [Role::Manager, true, true],
        'inventory staff' => [Role::InventoryStaff, true, true],
    ]);
});
