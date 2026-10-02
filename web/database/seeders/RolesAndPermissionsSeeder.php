<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the roles and permissions tables in line with the Role and
 * Permission enums. Safe to run repeatedly (and on every deploy): it creates
 * what is missing, resets each role's permissions to the enum definition, and
 * removes permissions that no longer exist.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        // DatabaseSeeder runs with model events disabled, which are what normally
        // refresh the package's cache, so do it explicitly: syncPermissions below
        // looks permissions up by name and would not find the ones just created.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web')
                ->syncPermissions(array_map(
                    fn (Permission $permission) => $permission->value,
                    $role->permissions(),
                ));
        }

        PermissionModel::query()
            ->whereNotIn('name', array_column(Permission::cases(), 'value'))
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
