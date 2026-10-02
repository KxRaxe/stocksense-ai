<?php

namespace App\Services\Sales;

use App\Enums\ImportStatus;
use App\Enums\StockMovementType;
use App\Jobs\ProcessSalesImportChunk;
use App\Models\ImportBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of an uploaded sales file: start it, confirm it, cancel it, undo it.
 */
class ImportManager
{
    public function __construct(
        private readonly SalesFileReader $reader,
        private readonly StockService $stock,
    ) {}

    /**
     * Reads the uploaded file and opens a batch in the "checking" stage, with
     * the columns guessed from the header names.
     *
     * @throws InvalidImportFile
     */
    public function start(UploadedFile $file, bool $adjustStock, User $user): ImportBatch
    {
        $parsed = $this->reader->read($file);

        return DB::transaction(function () use ($file, $parsed, $adjustStock, $user) {
            $batch = ImportBatch::create([
                'type' => 'sales',
                'filename' => $file->getClientOriginalName(),
                'status' => ImportStatus::Preview,
                'settings' => [
                    'headers' => $parsed['headers'],
                    'columns' => ImportFields::guess($parsed['headers']),
                    'date_format' => 'iso',
                    'adjust_stock' => $adjustStock,
                ],
                'user_id' => $user->getKey(),
            ]);

            $batch->rows_total = (new ImportRowsFile($batch))->write($parsed['rows']);
            $batch->save();

            // Reload so the counters the database defaults to zero are present on
            // the object, just as they are for a batch loaded by the queue.
            return $batch->refresh();
        });
    }

    /**
     * Hands the file to the queue.
     *
     * @throws ValidationException When a required column has not been chosen
     */
    public function confirm(ImportBatch $batch, User $user): void
    {
        $columns = $batch->settings['columns'];

        $missing = array_filter(ImportFields::required(), fn (string $field) => ($columns[$field] ?? null) === null);

        if ($missing !== []) {
            $names = array_map(fn (string $field) => ImportFields::all()[$field]['label'], $missing);

            throw ValidationException::withMessages([
                'columns' => 'Choose a column for: '.implode(', ', $names).'.',
            ]);
        }

        $batch->forceFill(['status' => ImportStatus::Queued])->save();

        activity('sales')
            ->performedOn($batch)
            ->causedBy($user)
            ->event('import_started')
            ->withProperties(['filename' => $batch->filename, 'rows' => $batch->rows_total])
            ->log('Sales import started');

        ProcessSalesImportChunk::dispatch($batch->id, 0);
    }

    /**
     * Abandons a file that has not been imported yet.
     */
    public function cancel(ImportBatch $batch): void
    {
        (new ImportRowsFile($batch))->delete();

        $batch->delete();
    }

    /**
     * Removes every sale an import created and puts back the stock they took,
     * as one correcting movement per product. The batch stays, marked undone.
     */
    public function undo(ImportBatch $batch, User $user): void
    {
        DB::transaction(function () use ($batch, $user) {
            // The stock an import took off is tied to the import itself.
            $taken = StockMovement::query()
                ->where('type', StockMovementType::Sale->value)
                ->where('reference_type', $batch->getMorphClass())
                ->where('reference_id', $batch->id)
                ->selectRaw('product_id, location_id, sum(quantity) as total')
                ->groupBy('product_id', 'location_id')
                ->get();

            foreach ($taken as $line) {
                $total = (int) $line->getAttribute('total');

                if ($total === 0) {
                    continue;
                }

                $this->stock->record(
                    Product::findOrFail($line->product_id),
                    StockMovementType::Adjustment,
                    -$total,
                    note: "Import #{$batch->id} undone",
                    user: $user,
                    reference: $batch,
                    location: Location::findOrFail($line->location_id),
                );
            }

            Sale::query()->where('import_batch_id', $batch->id)->delete();

            $batch->forceFill(['status' => ImportStatus::Undone])->save();

            activity('sales')
                ->performedOn($batch)
                ->causedBy($user)
                ->event('import_undone')
                ->withProperties(['filename' => $batch->filename])
                ->log('Sales import undone');
        });
    }
}
