<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per uploaded file. A batch moves from `preview` (file uploaded, the
 * person is checking the column mapping) through `queued` and `processing` to
 * `completed` or `failed`, and to `undone` if the import is reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('sales');
            $table->string('filename');
            $table->string('status', 20)->index();
            // Column mapping, date format and whether to leave stock alone.
            $table->jsonb('settings');
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_processed')->default(0);
            $table->unsignedInteger('rows_ok')->default(0);
            $table->unsignedInteger('rows_duplicate')->default(0);
            $table->unsignedInteger('rows_failed')->default(0);
            // The first few failed rows, for the screen. The full list is a file.
            $table->jsonb('errors')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
