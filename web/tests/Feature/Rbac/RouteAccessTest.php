<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->target = User::factory()->inventoryStaff()->create();
});

/**
 * Builds the URL for a user-administration route; the third dataset column
 * says whether the route is for one user (and so needs the target).
 */
function adminUrl(string $routeName, bool $forOneUser, User $target): string
{
    return route($routeName, $forOneUser ? [$target] : []);
}

/*
 * Every user-administration route: HTTP method, route name, and whether it
 * addresses a single user. Guests are sent to the login page; signed-in users
 * without the permission get 403.
 */
dataset('admin routes', [
    'list' => ['get', 'users.index', false],
    'new form' => ['get', 'users.create', false],
    'create' => ['post', 'users.store', false],
    'edit form' => ['get', 'users.edit', true],
    'update' => ['put', 'users.update', true],
    'deactivate' => ['patch', 'users.deactivate', true],
    'activate' => ['patch', 'users.activate', true],
    'send link' => ['post', 'users.setup-link', true],
]);

it('sends guests to the login page', function (string $method, string $routeName, bool $forOneUser) {
    $this->{$method}(adminUrl($routeName, $forOneUser, $this->target))
        ->assertRedirect(route('login'));
})->with('admin routes');

it('forbids managers and inventory staff', function (string $method, string $routeName, bool $forOneUser, Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->{$method}(adminUrl($routeName, $forOneUser, $this->target))
        ->assertForbidden();
})->with('admin routes')->with([
    'manager' => [Role::Manager],
    'inventory staff' => [Role::InventoryStaff],
]);

it('lets the owner open the pages', function (string $routeName, bool $forOneUser) {
    $this->actingAs(User::factory()->owner()->create())
        ->get(adminUrl($routeName, $forOneUser, $this->target))
        ->assertOk();
})->with([
    'list' => ['users.index', false],
    'new form' => ['users.create', false],
    'edit form' => ['users.edit', true],
]);

it('lets every role see the dashboard', function (Role $role) {
    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('dashboard'))
        ->assertOk();
})->with(Role::cases());

it('shares the role and permissions with the frontend', function (Role $role) {
    $expected = collect($role->permissions())->map->value->sort()->values()->all();

    $this->actingAs(User::factory()->withRole($role)->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.role', $role->value)
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === $expected));
})->with(Role::cases());
