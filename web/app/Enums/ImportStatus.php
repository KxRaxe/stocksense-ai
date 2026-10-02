<?php

namespace App\Enums;

/**
 * Where an uploaded file is in its life. A file starts in Preview while the
 * person checks the column mapping, is Queued when they confirm, then runs as
 * a series of short jobs (Processing) and ends Completed or Failed. An import
 * that has been reversed is Undone.
 */
enum ImportStatus: string
{
    case Preview = 'preview';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Undone = 'undone';

    public function label(): string
    {
        return match ($this) {
            self::Preview => 'Checking',
            self::Queued => 'Waiting to start',
            self::Processing => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Undone => 'Undone',
        };
    }

    /**
     * Whether the file is being worked on right now (the screen keeps
     * refreshing while this is true).
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /**
     * Whether the import has put sales into the system that an undo can remove.
     */
    public function canUndo(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
