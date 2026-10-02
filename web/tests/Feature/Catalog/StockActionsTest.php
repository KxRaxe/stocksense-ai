<?php

use App\Enums\Role;
use App\Models\InventoryLevel;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->product = Product::factory()->create();
    app(StockService::class)->openingStock($this->product, 10);
});

describe('restock', function () {
    it('adds stock for every role', function (Role $role) {
        $user = User::factory()->withRole($role)->create();

        $this->actingAs($user)
            ->post(route('products.restock', $this->product), ['quantity' => 15, 'note' => 'Supplier delivery'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $movement = StockMovement::where('type', 'restock')->firstOrFail();

        expect(InventoryLevel::first()->on_hand)->toBe(25)
            ->and($movement->user_id)->toBe($user->id)
            ->and($movement->note)->toBe('Supplier delivery');
    })->with(Role::cases());

    it('records an earlier received date for that day', function () {
        $date = CarbonImmutable::now()->subDays(3)->toDateString();

        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.restock', $this->product), ['quantity' => 5, 'received_on' => $date]);

        expect(StockMovement::where('type', 'restock')->first()->occurred_at->toDateString())->toBe($date);
    });

    it('treats today as now', function () {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.restock', $this->product), ['quantity' => 5, 'received_on' => now()->toDateString()]);

        expect(StockMovement::where('type', 'restock')->first()->occurred_at->diffInMinutes(now(), true))->toBeLessThan(2);
    });

    it('rejects bad input', function (array $payload, string $field) {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.restock', $this->product), $payload)
            ->assertSessionHasErrors($field);

        expect(InventoryLevel::first()->on_hand)->toBe(10);
    })->with([
        'zero quantity' => [['quantity' => 0], 'quantity'],
        'negative quantity' => [['quantity' => -5], 'quantity'],
        'fractional quantity' => [['quantity' => 2.5], 'quantity'],
        'no quantity' => [[], 'quantity'],
        'future date' => [['quantity' => 5, 'received_on' => '2999-01-01'], 'received_on'],
        'not a date' => [['quantity' => 5, 'received_on' => 'soon'], 'received_on'],
    ]);
});

describe('stock-take', function () {
    it('sets stock to the counted quantity for every role', function (Role $role) {
        $this->actingAs(User::factory()->withRole($role)->create())
            ->post(route('products.adjust', $this->product), ['counted' => 4, 'note' => 'Water damage'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $movement = StockMovement::where('type', 'adjustment')->firstOrFail();

        expect(InventoryLevel::first()->on_hand)->toBe(4)
            ->and($movement->quantity)->toBe(-6)
            ->and($movement->note)->toBe('Water damage');
    })->with(Role::cases());

    it('says so, and records nothing, when the count matches', function () {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.adjust', $this->product), ['counted' => 10, 'note' => 'Checked'])
            ->assertRedirect();

        expect(StockMovement::count())->toBe(1);
    });

    it('requires a reason', function () {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.adjust', $this->product), ['counted' => 4])
            ->assertSessionHasErrors('note');

        expect(InventoryLevel::first()->on_hand)->toBe(10);
    });

    it('rejects a negative count', function () {
        $this->actingAs(User::factory()->manager()->create())
            ->post(route('products.adjust', $this->product), ['counted' => -1, 'note' => 'Oops'])
            ->assertSessionHasErrors('counted');
    });
});
