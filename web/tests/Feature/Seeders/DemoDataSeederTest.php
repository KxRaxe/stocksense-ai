<?php

use App\Enums\ImportStatus;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
 * The seeder is run against a tiny dataset (tests/Fixtures/demo) whose final
 * numbers can be worked out by hand:
 *
 *   FB-001  100 opening + 60 delivered - (10 + 12 + 15 + 9) sold = 114
 *   FB-002  500 opening + 240 delivered - (40 + 55 + 30 + 48) sold = 567
 *   HW-001   50 opening + 30 delivered - (3 + 2 + 6 + 1) sold = 68
 */
beforeEach(function () {
    Storage::fake('local');
    config(['demo.data_path' => base_path('tests/Fixtures/demo')]);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(DemoUsersSeeder::class);
});

it('loads the catalog', function () {
    $this->seed(DemoDataSeeder::class);

    expect(Category::count())->toBe(2)
        ->and(Category::firstWhere('name', 'Hardware and construction')->service_level)->toBe('85.00')
        ->and(Category::firstWhere('name', 'Hardware and construction')->description)->toBe('Tools, materials')
        ->and(Product::count())->toBe(3);

    $coffee = Product::firstWhere('sku', 'FB-001');
    expect($coffee->unit_price)->toBe('125.00')
        ->and($coffee->lead_time_days)->toBe(5)
        ->and($coffee->pack_size)->toBe(6)
        ->and($coffee->reorder_point_override)->toBe(20)
        ->and($coffee->category->name)->toBe('Food and beverages')
        // A blank reorder point means "none set".
        ->and(Product::firstWhere('sku', 'FB-002')->reorder_point_override)->toBeNull();
});

it('dates opening stock to the day each product started selling', function () {
    $this->seed(DemoDataSeeder::class);

    $opening = StockMovement::where('type', 'initial')->get()->keyBy('product_id');

    expect($opening)->toHaveCount(3)
        ->and($opening[Product::firstWhere('sku', 'FB-001')->id]->occurred_at->toDateString())->toBe('2026-01-01')
        ->and($opening[Product::firstWhere('sku', 'HW-001')->id]->occurred_at->toDateString())->toBe('2026-02-01');
});

it('records the deliveries', function () {
    $this->seed(DemoDataSeeder::class);

    $deliveries = StockMovement::where('type', 'restock')->orderBy('occurred_at')->get();

    expect($deliveries)->toHaveCount(3)
        ->and($deliveries->pluck('quantity')->all())->toBe([60, 240, 30])
        ->and($deliveries->pluck('occurred_at')->map->toDateString()->all())->toBe(['2026-01-10', '2026-01-15', '2026-02-20'])
        ->and($deliveries->pluck('note')->unique()->all())->toBe(['Delivery']);
});

it('imports the sales through the real import, as the demo owner', function () {
    $this->seed(DemoDataSeeder::class);

    $batch = ImportBatch::firstOrFail();

    expect(ImportBatch::count())->toBe(1)
        ->and($batch->filename)->toBe('demo-sales.csv')
        ->and($batch->status)->toBe(ImportStatus::Completed)
        ->and($batch->rows_total)->toBe(12)
        ->and($batch->rows_ok)->toBe(12)
        ->and($batch->rows_failed)->toBe(0)
        ->and($batch->user_id)->toBe(User::firstWhere('email', 'owner@stocksense.test')->id)
        ->and(Sale::count())->toBe(12)
        ->and(Sale::where('import_batch_id', $batch->id)->count())->toBe(12);
});

it('leaves the stock levels the history adds up to', function () {
    $this->seed(DemoDataSeeder::class);

    $onHand = fn (string $sku) => InventoryLevel::where('product_id', Product::firstWhere('sku', $sku)->id)->value('on_hand');

    expect($onHand('FB-001'))->toBe(114)
        ->and($onHand('FB-002'))->toBe(567)
        ->and($onHand('HW-001'))->toBe(68);
});

it('keeps the ledger adding up to every stock level', function () {
    $this->seed(DemoDataSeeder::class);

    foreach (InventoryLevel::all() as $level) {
        expect((int) StockMovement::where('product_id', $level->product_id)->sum('quantity'))->toBe($level->on_hand);
    }

    // 3 opening + 3 deliveries + 12 sales
    expect(StockMovement::count())->toBe(18);
});

it('does not run twice over the same data', function () {
    $this->seed(DemoDataSeeder::class);
    $this->seed(DemoDataSeeder::class);

    expect(Product::count())->toBe(3)
        ->and(Sale::count())->toBe(12)
        ->and(StockMovement::count())->toBe(18)
        ->and(ImportBatch::count())->toBe(1);
});

it('can be undone like any other import', function () {
    $this->seed(DemoDataSeeder::class);
    $owner = User::firstWhere('email', 'owner@stocksense.test');

    $this->actingAs($owner)->post(route('sales.imports.undo', ImportBatch::firstOrFail()))->assertRedirect();

    $onHand = fn (string $sku) => InventoryLevel::where('product_id', Product::firstWhere('sku', $sku)->id)->value('on_hand');

    // Back to opening stock plus deliveries.
    expect(Sale::count())->toBe(0)
        ->and($onHand('FB-001'))->toBe(160)
        ->and($onHand('FB-002'))->toBe(740)
        ->and($onHand('HW-001'))->toBe(80);
});

it('says so when a data file is missing', function () {
    config(['demo.data_path' => base_path('tests/Fixtures/nowhere')]);

    expect(fn () => $this->seed(DemoDataSeeder::class))
        ->toThrow(RuntimeException::class, 'Demo data file not found');
});

describe('the main seeder', function () {
    it('loads the demo accounts and data outside production', function () {
        $this->seed(DatabaseSeeder::class);

        expect(User::where('email', 'owner@stocksense.test')->exists())->toBeTrue()
            ->and(Product::count())->toBe(3);
    });

    it('loads only the roles in production', function () {
        // The test setup made the demo users; clear them to see whether this run does.
        User::query()->delete();

        $this->app->instance('env', 'production');

        // As on a real server: Laravel asks for confirmation in production unless forced.
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

        expect(User::where('email', 'like', '%@stocksense.test')->exists())->toBeFalse()
            ->and(Product::count())->toBe(0)
            ->and(Role::count())->toBe(3);
    });
});

describe('the demo seeders in production', function () {
    beforeEach(function () {
        User::query()->delete();
        $this->app->instance('env', 'production');
    });

    it('refuse to create accounts with a known password, even when asked for by name', function () {
        expect(fn () => $this->artisan('db:seed', ['--class' => DemoUsersSeeder::class, '--force' => true])->run())
            ->toThrow(RuntimeException::class, 'for development and demonstrations only');

        expect(User::where('email', 'like', '%@stocksense.test')->exists())->toBeFalse();
    });

    it('refuse to load the demo shop', function () {
        expect(fn () => $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->run())
            ->toThrow(RuntimeException::class, 'for development and demonstrations only');

        expect(Product::count())->toBe(0);
    });

    it('can be allowed for a throwaway test stack', function () {
        config(['demo.allowed' => true]);

        $this->artisan('db:seed', ['--class' => DemoUsersSeeder::class, '--force' => true])->assertSuccessful();

        expect(User::where('email', 'owner@stocksense.test')->exists())->toBeTrue();
    });
});
