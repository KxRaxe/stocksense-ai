<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * The access matrix from the proposal, written out independently of the Role
 * enum so a mistake in the enum is caught here.
 *
 *   Capability                          Owner  Manager  Inventory staff
 *   Users and settings                    x
 *   Audit log                             x
 *   Products and categories               x       x       view only
 *   Stock levels                          x       x       view and edit
 *   Sales entry and import                x       x       x
 *   Run forecasts                         x       x
 *   Recommendations                       x       x       view only
 *   Reports                               x       x       inventory only
 *
 * @return array<string, array{0: Permission, 1: list<Role>}>
 */
function rbacAccessMatrix(): array
{
    $everyone = [Role::Owner, Role::Manager, Role::InventoryStaff];
    $management = [Role::Owner, Role::Manager];
    $owner = [Role::Owner];

    return [
        'users.manage' => [Permission::ManageUsers, $owner],
        'settings.manage' => [Permission::ManageSettings, $owner],
        'audit.view' => [Permission::ViewAuditLog, $owner],

        'catalog.view' => [Permission::ViewCatalog, $everyone],
        'catalog.manage' => [Permission::ManageCatalog, $management],
        'inventory.view' => [Permission::ViewInventory, $everyone],
        'inventory.adjust' => [Permission::AdjustStock, $everyone],

        'sales.view' => [Permission::ViewSales, $everyone],
        'sales.enter' => [Permission::EnterSales, $everyone],
        'sales.import' => [Permission::ImportSales, $everyone],

        'forecasts.view' => [Permission::ViewForecasts, $management],
        'forecasts.run' => [Permission::RunForecasts, $management],
        'recommendations.view' => [Permission::ViewRecommendations, $everyone],
        'recommendations.decide' => [Permission::DecideRecommendations, $management],

        'reports.view' => [Permission::ViewReports, $management],
        'reports.inventory' => [Permission::ViewInventoryReports, $everyone],
    ];
}

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

dataset('matrix', fn () => rbacAccessMatrix());

it('grants each permission to exactly the roles in the matrix', function (Permission $permission, array $allowed) {
    foreach (Role::cases() as $role) {
        $user = User::factory()->withRole($role)->create();

        expect($user->hasPermissionTo($permission))
            ->toBe(in_array($role, $allowed, true), "{$role->value} / {$permission->value}");
    }
})->with('matrix');

it('lists every permission in the matrix, so a new one cannot be forgotten', function () {
    $listed = array_map(fn (array $row) => $row[0]->value, rbacAccessMatrix());

    expect(array_values($listed))->toEqualCanonicalizing(array_column(Permission::cases(), 'value'));
});

it('is idempotent and prunes permissions that no longer exist', function () {
    PermissionModel::findOrCreate('retired.permission', 'web');
    RoleModel::findByName(Role::Manager->value)->givePermissionTo('retired.permission');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::pluck('name')->all())
        ->toEqualCanonicalizing(array_column(Permission::cases(), 'value'))
        ->and(RoleModel::count())->toBe(count(Role::cases()))
        ->and(RoleModel::findByName(Role::Manager->value)->permissions->count())
        ->toBe(count(Role::Manager->permissions()));
});
