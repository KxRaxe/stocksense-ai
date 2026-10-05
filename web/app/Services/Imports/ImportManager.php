<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Jobs\ProcessImportChunk;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The life of an uploaded file, whatever it imports: start it, confirm it,
 * cancel it, undo it. What differs between kinds of file lives in that kind's
 * ImportDefinition.
 */
class ImportManager
{
    public function __construct(private readonly ImportFileReader $reader) {}

    /**
     * Reads the uploaded file and opens a batch in the "checking" stage, with
     * the columns guessed from the header names.
     *
     * @param  array<string, mixed>  $input  The choices made at upload (see the definition's options)
     *
     * @throws InvalidImportFile
     */
    public function start(ImportType $type, UploadedFile $file, array $input, User $user): ImportBatch
    {
        $definition = $type->definition();
        $parsed = $this->reader->read($file);

        return DB::transaction(function () use ($type, $definition, $file, $parsed, $input, $user) {
            $batch = ImportBatch::create([
                'type' => $type,
                'filename' => $file->getClientOriginalName(),
                'status' => ImportStatus::Preview,
                'settings' => [
                    'headers' => $parsed['headers'],
                    'columns' => HeaderGuesser::guess($definition->fields(), $parsed['headers']),
                    ...$definition->initialOptions($input),
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
        $definition = $batch->type->definition();
        $columns = $batch->settings['columns'];

        $missing = array_filter(
            array_keys(array_filter($definition->fields(), fn (array $field) => $field['required'])),
            fn (string $field) => ($columns[$field] ?? null) === null,
        );

        if ($missing !== []) {
            $names = array_map(fn (string $field) => $definition->fields()[$field]['label'], $missing);

            throw ValidationException::withMessages([
                'columns' => 'Choose a column for: '.implode(', ', $names).'.',
            ]);
        }

        $batch->forceFill(['status' => ImportStatus::Queued])->save();

        activity($batch->type->value)
            ->performedOn($batch)
            ->causedBy($user)
            ->event('import_started')
            ->withProperties(['filename' => $batch->filename, 'rows' => $batch->rows_total])
            ->log(ucfirst($batch->type->value).' import started');

        ProcessImportChunk::dispatch($batch->id, 0);
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
     * Reverses an import as its type defines, and marks the batch undone.
     */
    public function undo(ImportBatch $batch, User $user): void
    {
        DB::transaction(function () use ($batch, $user) {
            $batch->type->definition()->undo($batch, $user);

            $batch->forceFill(['status' => ImportStatus::Undone])->save();

            activity($batch->type->value)
                ->performedOn($batch)
                ->causedBy($user)
                ->event('import_undone')
                ->withProperties(['filename' => $batch->filename])
                ->log(ucfirst($batch->type->value).' import undone');
        });
    }
}
