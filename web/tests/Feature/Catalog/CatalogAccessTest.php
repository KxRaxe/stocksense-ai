<?php

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\Role;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->category = Category::factory()->create();
    $this->product = Product::factory()->for($this->category)->create();

    $this->batch = ImportBatch::create([
        'type' => ImportType::Products,
        'filename' => 'products.csv',
        'status' => ImportStatus::Completed,
        'settings' => [
            'headers' => ['sku'],
            'columns' => ['sku' => 0],
            'existing_skus' => 'skip',
            'create_categories' => false,
        ],
    ]);
});

/**
 * Builds the URL for a catalog route. `$subject` says which record the route
 * needs: 'product', 'category', 'batch' (a product import), or null.
 */
function catalogUrl(string $routeName, ?string $subject): string
{
    $parameters = match ($subject) {
        'product' => [test()->product],
        'category' => [test()->category],
        'batch' => [test()->batch],
        default => [],
    };

    return route($routeName, $parameters);
}

$everyone = [Role::Owner, Role::Manager, Role::InventoryStaff];
$management = [Role::Owner, Role::Manager];

/*
 * Every catalog route: method, route name, the record it needs, and which
 * roles may use it. Anyone else gets 403. Matches the access matrix: viewing
 * the catalog and stock is open to all, recording stock is open to all, and
 * changing products and categories is Owner and Manager only.
 */
dataset('catalog routes', [
    'category list' => ['get', 'categories.index', null, $everyone],
    'category new form' => ['get', 'categories.create', null, $management],
    'category create' => ['post', 'categories.store', null, $management],
    'category edit form' => ['get', 'categories.edit', 'category', $management],
    'category update' => ['put', 'categories.update', 'category', $management],
    'category delete' => ['delete', 'categories.destroy', 'category', $management],

    'product list' => ['get', 'products.index', null, $everyone],
    'product page' => ['get', 'products.show', 'product', $everyone],
    'product new form' => ['get', 'products.create', null, $management],
    'product create' => ['post', 'products.store', null, $management],
    'product edit form' => ['get', 'products.edit', 'product', $management],
    'product update' => ['put', 'products.update', 'product', $management],
    'product archive' => ['patch', 'products.archive', 'product', $management],
    'product restore' => ['patch', 'products.restore', 'product', $management],

    'import history' => ['get', 'products.imports.index', null, $management],
    'import upload form' => ['get', 'products.imports.create', null, $management],
    'import template' => ['get', 'products.imports.template', null, $management],
    'import upload' => ['post', 'products.imports.store', null, $management],
    'import preview' => ['get', 'products.imports.show', 'batch', $management],
    'import settings' => ['put', 'products.imports.update', 'batch', $management],
    'import start' => ['post', 'products.imports.confirm', 'batch', $management],
    'import error report' => ['get', 'products.imports.errors', 'batch', $management],
    'import undo' => ['post', 'products.imports.undo', 'batch', $management],
    'import cancel' => ['delete', 'products.imports.cancel', 'batch', $management],

    'inventory overview' => ['get', 'inventory.index', null, $everyone],
    'record restock' => ['post', 'products.restock', 'product', $everyone],
    'record stock-take' => ['post', 'products.adjust', 'product', $everyone],
]);

it('sends guests to the login page', function (string $method, string $routeName, ?string $subject) {
    $this->{$method}(catalogUrl($routeName, $subject))->assertRedirect(route('login'));
})->with('catalog routes');

it('allows exactly the roles in the matrix', function (string $method, string $routeName, ?string $subject, array $allowed, Role $role) {
    $response = $this->actingAs(User::factory()->withRole($role)->create())
        ->{$method}(catalogUrl($routeName, $subject));

    if (in_array($role, $allowed, true)) {
        // Allowed: whatever happens next (a page, a redirect, a validation
        // error for the empty form), it is not a refusal.
        expect($response->getStatusCode())->not->toBe(403);
    } else {
        $response->assertForbidden();
    }
})->with('catalog routes')->with(Role::cases());
