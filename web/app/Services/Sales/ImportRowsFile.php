<?php

namespace App\Services\Sales;

use App\Models\ImportBatch;
use Generator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SplFileObject;

/**
 * The uploaded file, turned into one plain line per data row so it can be
 * read quickly, a slice at a time, however large the original spreadsheet was.
 * Each line is `[row number in the original file, [cell, cell, ...]]`.
 *
 * Also holds the rows that failed, for the downloadable error report. Needs a
 * disk whose files are on the server's own filesystem.
 */
class ImportRowsFile
{
    public function __construct(private readonly ImportBatch $batch) {}

    /**
     * @param  iterable<array{0: int, 1: list<mixed>}>  $rows
     * @return int How many rows were written
     */
    public function write(iterable $rows): int
    {
        $this->disk()->makeDirectory($this->batch->directory());

        $file = new SplFileObject($this->path($this->batch->rowsPath()), 'wb');
        $count = 0;

        foreach ($rows as [$number, $cells]) {
            $file->fwrite(json_encode([$number, $cells], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            $count++;
        }

        return $count;
    }

    /**
     * @return Generator<int, array{0: int, 1: list<mixed>}>
     */
    public function read(int $offset = 0, ?int $limit = null): Generator
    {
        yield from $this->lines($this->batch->rowsPath(), $offset, $limit);
    }

    /**
     * @param  list<array{row: int, messages: list<string>, cells: list<mixed>}>  $failed
     */
    public function appendErrors(array $failed): void
    {
        if ($failed === []) {
            return;
        }

        $this->disk()->makeDirectory($this->batch->directory());

        $file = new SplFileObject($this->path($this->batch->errorsPath()), 'ab');

        foreach ($failed as $row) {
            $file->fwrite(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        }
    }

    /**
     * @return Generator<int, array{row: int, messages: list<string>, cells: list<mixed>}>
     */
    public function errors(): Generator
    {
        yield from $this->lines($this->batch->errorsPath());
    }

    public function hasErrors(): bool
    {
        return is_file($this->path($this->batch->errorsPath()));
    }

    public function delete(): void
    {
        $this->disk()->deleteDirectory($this->batch->directory());
    }

    /**
     * @return Generator<int, mixed>
     */
    private function lines(string $relativePath, int $offset = 0, ?int $limit = null): Generator
    {
        $path = $this->path($relativePath);

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'rb') ?: throw new RuntimeException("Could not open {$relativePath}.");

        try {
            // Lines are not fixed-width, so reaching a row means reading past the
            // ones before it. That is quick next to the database work per row.
            for ($skipped = 0; $skipped < $offset && fgets($handle) !== false; $skipped++);

            $read = 0;

            while (($limit === null || $read < $limit) && ($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);

                $read++;
            }
        } finally {
            fclose($handle);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('imports.disk'));
    }

    private function path(string $relativePath): string
    {
        return $this->disk()->path($relativePath);
    }
}
