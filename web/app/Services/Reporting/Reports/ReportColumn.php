<?php

namespace App\Services\Reporting\Reports;

/**
 * One column of a report: what it is called and what kind of value it holds,
 * which decides how it is written on screen, in Excel and in the PDF.
 */
final class ReportColumn
{
    public const TEXT = 'text';

    public const INTEGER = 'integer';

    public const DECIMAL = 'decimal';

    public const MONEY = 'money';

    /** A figure that is already a percentage (16.3 means 16.3%). */
    public const PERCENT = 'percent';

    /** A calendar date, "2026-10-05". */
    public const DATE = 'date';

    /** A moment, as an ISO 8601 timestamp. */
    public const DATETIME = 'datetime';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type = self::TEXT,
    ) {}

    /**
     * Whether the column is a number, and so is right-aligned.
     */
    public function isNumeric(): bool
    {
        return in_array($this->type, [self::INTEGER, self::DECIMAL, self::MONEY, self::PERCENT], true);
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type];
    }
}
