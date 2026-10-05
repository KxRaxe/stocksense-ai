<?php

namespace App\Services\Imports;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Everything that differs between kinds of import: which columns the file can
 * carry, the choices the person makes, how each row is checked and saved, and
 * how an import is undone. The shared flow (upload, preview, queue, error
 * report, undo) is the same for all of them.
 */
interface ImportDefinition
{
    /**
     * The columns a file can carry: field => label, whether it is required, and
     * the header names it is known by (lower case, letters and digits only, best
     * match first).
     *
     * @return array<string, array{label: string, required: bool, aliases: list<string>}>
     */
    public function fields(): array;

    /**
     * The choices the person can make, as form descriptors. Those with
     * `upload` set are asked for when the file is uploaded; all are shown in
     * the preview.
     *
     * @return list<array{
     *     name: string,
     *     label: string,
     *     kind: 'select'|'radio'|'checkbox',
     *     upload: bool,
     *     choices: list<array{value: string, label: string, help?: string}>,
     *     help?: string
     * }>
     */
    public function options(): array;

    /**
     * Validation for the options asked for at upload.
     *
     * @return array<string, list<ValidationRule|string>>
     */
    public function uploadRules(): array;

    /**
     * Validation for the options when the preview settings are saved.
     *
     * @return array<string, list<ValidationRule|string>>
     */
    public function settingsRules(): array;

    /**
     * The option values a new batch starts with.
     *
     * @param  array<string, mixed>  $input  What was chosen at upload
     * @return array<string, mixed>
     */
    public function initialOptions(array $input): array;

    /**
     * The option values after the preview settings are saved.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function updatedOptions(array $validated): array;

    /**
     * Each option's current value as text ("1"/"0" for yes/no), for the form.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public function optionValues(array $settings): array;

    public function processor(): ImportProcessor;

    /**
     * Checks the whole file against the chosen settings without saving
     * anything: counts, a sample of rows, and the first problems.
     *
     * @return array{
     *     importable_rows: int,
     *     import_label: string,
     *     figures: list<array{label: string, value: int, tone: 'good'|'neutral'|'warn'}>,
     *     notes: list<string>,
     *     sample: list<array{row: int, cells: list<mixed>, status: 'ok'|'skip'|'error', label: string, messages: list<string>}>,
     *     problems: list<array{row: int, messages: list<string>}>,
     *     invalid_rows: int
     * }
     */
    public function preview(ImportBatch $batch): array;

    /**
     * The counts shown on a finished import.
     *
     * @return list<array{label: string, value: int}>
     */
    public function resultFigures(ImportBatch $batch): array;

    /**
     * Reverses an import. Called inside a database transaction.
     */
    public function undo(ImportBatch $batch, User $user): void;

    /**
     * What undoing will do, in plain words, for the confirmation.
     */
    public function undoDescription(ImportBatch $batch): string;

    /**
     * Whether an import with this outcome has anything an undo would remove.
     */
    public function hasUndoableChanges(ImportBatch $batch): bool;

    /**
     * An example file: the header row, then a couple of example rows.
     *
     * @return list<list<string|int>>
     */
    public function template(): array;

    /**
     * The page listing what an import brought in.
     */
    public function resultsUrl(ImportBatch $batch): string;

    /**
     * The link text for that page.
     */
    public function resultsLabel(): string;
}
