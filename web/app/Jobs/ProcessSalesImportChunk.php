<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Services\Sales\SalesImportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports one slice of an uploaded file, then queues the next slice. Short
 * jobs, one after another, keep each well inside the queue worker's time
 * limit however big the file is, and the screen can show progress as it goes.
 */
class ProcessSalesImportChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A slice that fails is not retried: the batch is marked failed, and the
    // person can undo it or upload the file again.
    public int $tries = 1;

    public function __construct(public readonly int $batchId, public readonly int $offset)
    {
        $this->onQueue('imports');
    }

    public function handle(SalesImportProcessor $processor): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        // Cancelled, undone or finished in the meantime.
        if ($batch === null || ! in_array($batch->status, [ImportStatus::Queued, ImportStatus::Processing], true)) {
            return;
        }

        if ($batch->status === ImportStatus::Queued) {
            $batch->forceFill(['status' => ImportStatus::Processing, 'started_at' => now()])->save();
        }

        if ($processor->processNext($batch, $this->offset)) {
            self::dispatch($batch->id, $batch->rows_processed);

            return;
        }

        $batch->forceFill(['status' => ImportStatus::Completed, 'finished_at' => now()])->save();
    }

    public function failed(Throwable $exception): void
    {
        ImportBatch::query()->whereKey($this->batchId)->update([
            'status' => ImportStatus::Failed->value,
            'error_message' => 'The import stopped because of an unexpected error. Rows imported before it stay; you can undo the import and try again.',
            'finished_at' => now(),
        ]);

        report($exception);
    }
}
