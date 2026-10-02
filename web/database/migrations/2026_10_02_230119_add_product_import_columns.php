<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            // Products already in the catalogue that a file updated (product imports).
            $table->unsignedInteger('rows_updated')->default(0)->after('rows_duplicate');
        });

        Schema::table('products', function (Blueprint $table) {
            // The import that created the product, so that import can be undone.
            $table->foreignId('import_batch_id')->nullable()->after('is_active')
                ->constrained('import_batches')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('import_batch_id');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('rows_updated');
        });
    }
};
