<?php

use App\Enums\ImportStatus;
use App\Enums\Role;
use App\Models\ImportBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $product = Product::factory()->create();

    $this->sale = Sale::create([
        'product_id' => $product->id,
        'location_id' => Location::defaultLocation()->id,
        'sold_on' => '2026-03-05',
        'quantity' => 1,
        'unit_price' => 10,
        'total' => 10,
        'source' => 'manual',
    ]);

    $this->batch = ImportBatch::create([
        'filename' => 'sales.csv',
        'status' => ImportStatus::Completed,
        'settings' => ['headers' => ['a'], 'columns' => ['date' => 0, 'sku' => null, 'quantity' => null, 'unit_price' => null], 'date_format' => 'iso', 'adjust_stock' => false],
    ]);
});

/**
 * Builds the URL for a sales route; `$subject` says which record it needs.
 */
function salesUrl(string $routeName, ?string $subject): string
{
    return route($routeName, match ($subject) {
        'sale' => [test()->sale],
        'batch' => [test()->batch],
        default => [],
    });
}

/*
 * Every sales route. Under the access matrix everyone may view sales, enter
 * them and import them, so no signed-in role is ever refused; guests are sent
 * to the login page.
 */
dataset('sales routes', [
    'sales list' => ['get', 'sales.index', null],
    'new sales form' => ['get', 'sales.create', null],
    'save sales' => ['post', 'sales.store', null],
    'delete a sale' => ['delete', 'sales.destroy', 'sale'],
    'import history' => ['get', 'sales.imports.index', null],
    'upload form' => ['get', 'sales.imports.create', null],
    'template' => ['get', 'sales.imports.template', null],
    'upload a file' => ['post', 'sales.imports.store', null],
    'an import' => ['get', 'sales.imports.show', 'batch'],
    'change settings' => ['put', 'sales.imports.update', 'batch'],
    'start an import' => ['post', 'sales.imports.confirm', 'batch'],
    'error report' => ['get', 'sales.imports.errors', 'batch'],
    'undo an import' => ['post', 'sales.imports.undo', 'batch'],
    'cancel an import' => ['delete', 'sales.imports.cancel', 'batch'],
]);

it('sends guests to the login page', function (string $method, string $routeName, ?string $subject) {
    $this->{$method}(salesUrl($routeName, $subject))->assertRedirect(route('login'));
})->with('sales routes');

it('never refuses a signed-in role', function (string $method, string $routeName, ?string $subject, Role $role) {
    $response = $this->actingAs(User::factory()->withRole($role)->create())
        ->{$method}(salesUrl($routeName, $subject));

    // Whatever happens next (a page, a redirect, a validation error for an empty
    // form, "that import has moved on"), it is not a permission refusal.
    expect($response->getStatusCode())->not->toBe(403);
})->with('sales routes')->with(Role::cases());
