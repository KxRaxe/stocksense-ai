<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Safe everywhere, including production: it only manages roles/permissions.
        $this->call(RolesAndPermissionsSeeder::class);

        // Demo accounts for local development and the thesis demo. Never created
        // in production; there, the first Owner is created with
        // `php artisan app:create-owner`.
        if (! app()->isProduction()) {
            $this->call(DemoUsersSeeder::class);
            $this->call(DemoCatalogSeeder::class);
        }
    }
}
