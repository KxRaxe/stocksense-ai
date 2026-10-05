<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Does the work of one import type, a slice of the file at a time. A slice is
 * all-or-nothing, and the batch remembers how far it got, so a slice that is
 * run twice does no harm.
 */
abstract class ImportProcessor
{
    /**
     * Imports the next slice of the file.
     *
     * @param  int  $offset  Where the slice starts; ignored if the batch has already got past it
     * @return bool Whether rows remain after this slice
     */
    public function processNext(ImportBatch $batch, int $offset): bool
    {
        // Already done (for example a job that was retried): nothing to do.
        if ($batch->rows_processed !== $offset) {
            return $batch->rows_processed < $batch->rows_total;
        }

        $file = new ImportRowsFile($batch);
        $rows = iterator_to_array($file->read($offset, config('imports.chunk_size')), false);

        if ($rows === []) {
            return false;
        }

        // The queue has no signed-in person, so say whose changes these are for
        // the audit log.
        $failed = app(CauserResolver::class)->withCauser(
            $batch->user,
            fn () => DB::transaction(fn () => $this->importSlice($batch, $rows)),
        );

        // Written after the slice is safely saved, so a failed slice leaves no
        // half-finished report behind.
        $file->appendErrors($failed);

        return $batch->rows_processed < $batch->rows_total;
    }

    /**
     * Validates and saves one slice, and updates the batch's counts.
     *
     * @param  list<array{0: int, 1: list<mixed>}>  $rows
     * @return list<array{row: int, messages: list<string>, cells: list<mixed>}> The rows that failed
     */
    abstract protected function importSlice(ImportBatch $batch, array $rows): array;

    /**
     * The rows that failed, with their cells, for the error report.
     *
     * @param  list<array{0: int, 1: list<mixed>}>  $rows
     * @param  array<int, list<string>>  $invalid  Problems keyed by row number
     * @return list<array{row: int, messages: list<string>, cells: list<mixed>}>
     */
    protected function failedRows(array $rows, array $invalid): array
    {
        $failed = [];

        foreach ($rows as [$number, $cells]) {
            if (isset($invalid[$number])) {
                $failed[] = ['row' => $number, 'messages' => $invalid[$number], 'cells' => $cells];
            }
        }

        return $failed;
    }

    /**
     * The batch's stored problems with this slice's added, up to the limit
     * shown on screen (the full list is in the error report).
     *
     * @param  list<array{row: int, messages: list<string>, cells: list<mixed>}>  $failed
     * @return list<array{row: int, messages: list<string>}>|null
     */
    protected function keptErrors(ImportBatch $batch, array $failed): ?array
    {
        $kept = $batch->errors ?? [];

        foreach ($failed as $problem) {
            if (count($kept) >= config('imports.stored_errors')) {
                break;
            }

            $kept[] = ['row' => $problem['row'], 'messages' => $problem['messages']];
        }

        return $kept === [] ? null : $kept;
    }
}
