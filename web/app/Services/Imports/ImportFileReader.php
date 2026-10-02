<?php

namespace App\Services\Imports;

use DateTimeInterface;
use Generator;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Reads an uploaded CSV or Excel file: finds the header row and hands back
 * the data rows with their original row numbers. Only the first sheet is read.
 */
class ImportFileReader
{
    /**
     * @return array{headers: list<string>, rows: Generator<int, array{0: int, 1: list<mixed>}>}
     *
     * @throws InvalidImportFile
     */
    public function read(UploadedFile $file): array
    {
        try {
            $sheets = Excel::toArray(new class implements ToArray
            {
                public function array(array $array): void {}
            }, $file);
        } catch (Throwable $e) {
            report($e);

            throw new InvalidImportFile('This file could not be read. Upload a CSV or Excel (.xlsx) file.');
        }

        $sheet = array_values($sheets[0] ?? []);

        // The header is the first row that has anything in it.
        $headerIndex = null;
        foreach ($sheet as $index => $cells) {
            if ($this->hasContent($cells)) {
                $headerIndex = $index;

                break;
            }
        }

        if ($headerIndex === null) {
            throw new InvalidImportFile('The file is empty.');
        }

        $headers = [];
        foreach (array_values($sheet[$headerIndex]) as $position => $cell) {
            $text = $this->clean($cell);
            $headers[] = $text === null ? 'Column '.($position + 1) : (string) $text;
        }

        $dataRows = array_slice($sheet, $headerIndex + 1, null, true);
        $count = count(array_filter($dataRows, fn ($cells) => $this->hasContent($cells)));

        if ($count === 0) {
            throw new InvalidImportFile('The file has a header row but no data rows.');
        }

        if ($count > config('imports.max_rows')) {
            throw new InvalidImportFile(sprintf(
                'The file has %s data rows; the most one file can have is %s. Split it into smaller files.',
                number_format($count),
                number_format(config('imports.max_rows')),
            ));
        }

        return ['headers' => $headers, 'rows' => $this->rows($dataRows)];
    }

    /**
     * @param  array<int, mixed>  $dataRows  Keyed by position in the sheet
     * @return Generator<int, array{0: int, 1: list<mixed>}>
     */
    private function rows(array $dataRows): Generator
    {
        foreach ($dataRows as $index => $cells) {
            if (! $this->hasContent($cells)) {
                continue;
            }

            // Row numbers match what the person sees in their spreadsheet (the
            // first row is row 1).
            yield [$index + 1, array_map(fn ($cell) => $this->clean($cell), array_values((array) $cells))];
        }
    }

    private function hasContent(mixed $cells): bool
    {
        foreach ((array) $cells as $cell) {
            if ($this->clean($cell) !== null) {
                return true;
            }
        }

        return false;
    }

    private function clean(mixed $cell): int|float|string|null
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m-d');
        }

        if (is_bool($cell)) {
            return $cell ? 'TRUE' : 'FALSE';
        }

        if (is_string($cell)) {
            $cell = trim($cell);

            return $cell === '' ? null : $cell;
        }

        return is_int($cell) || is_float($cell) ? $cell : null;
    }
}
