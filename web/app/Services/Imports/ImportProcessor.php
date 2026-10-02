<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;

/**
 * Does the work of one import type, a slice of the file at a time.
 */
interface ImportProcessor
{
    /**
     * Imports the next slice of the file. Safe to run twice for the same slice:
     * the batch remembers how far it got.
     *
     * @param  int  $offset  Where the slice starts; ignored if the batch has already got past it
     * @return bool Whether rows remain after this slice
     */
    public function processNext(ImportBatch $batch, int $offset): bool;
}
