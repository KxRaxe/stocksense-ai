<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock is kept per location so branches can be added later without
 * migrating data (see docs/future-multi-branch.md). Today there is exactly one
 * location, created here so it exists in every environment, including tests
 * and production, without needing a seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 20)->unique();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        // At most one default location.
        DB::statement('CREATE UNIQUE INDEX locations_single_default ON locations (is_default) WHERE is_default');

        DB::table('locations')->insert([
            'name' => 'Main store',
            'code' => 'MAIN',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
