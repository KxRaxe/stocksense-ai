<?php

namespace App\Notifications;

use App\Enums\ImportType;
use App\Enums\NotificationType;

/**
 * A file the person imported finished with rows that could not be imported
 * (or stopped partway). The file's name is deliberately not used: people name
 * files after people.
 */
class ImportErrorsNotification extends StockSenseNotification
{
    /**
     * @param  string  $path  The import's page inside the app, which has the error report
     */
    public function __construct(
        private readonly int $batchId,
        private readonly ImportType $kind,
        private readonly int $imported,
        private readonly int $failed,
        private readonly bool $stopped,
        private readonly string $path,
    ) {}

    public static function type(): NotificationType
    {
        return NotificationType::ImportErrors;
    }

    private function name(): string
    {
        return ucfirst($this->kind->label())." import #{$this->batchId}";
    }

    protected function title(): string
    {
        return $this->stopped ? "{$this->name()} stopped" : "{$this->name()} had problems";
    }

    protected function summary(): string
    {
        return $this->stopped
            ? 'It stopped before it finished. What was imported so far can be undone.'
            : number_format($this->failed).' '.$this->plural($this->failed, 'row').' could not be imported.';
    }

    protected function path(): string
    {
        return $this->path;
    }

    protected function actionText(): string
    {
        return 'Review the import';
    }

    protected function lines(): array
    {
        $lines = [$this->stopped
            ? "{$this->name()} stopped before it finished because of an unexpected error."
            : "{$this->name()} finished, but ".number_format($this->failed).' '.$this->plural($this->failed, 'row').' could not be imported.'];

        $lines[] = number_format($this->imported).' '.$this->plural($this->imported, 'row').' went in.';
        $lines[] = $this->stopped
            ? 'You can undo the import and try again.'
            : 'The import page lists each problem and has an error report you can download, fix and upload again.';

        return $lines;
    }
}
