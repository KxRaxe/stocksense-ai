<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One demo account per role for local development and demonstrations.
 * All three use the password "password". Not run in production.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['Demo Owner', 'owner@stocksense.test', Role::Owner],
            ['Demo Manager', 'manager@stocksense.test', Role::Manager],
            ['Demo Inventory Staff', 'staff@stocksense.test', Role::InventoryStaff],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => 'password'],
            );

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
            $user->syncRoles($role->value);
        }
    }
}
