<?php

namespace App\Services\Reporting\Reports;

use Carbon\CarbonImmutable;

/**
 * Writes a report's raw values as text, for the PDF and the summary sheet of
 * a spreadsheet. (The screen does the same in the browser, and the data sheet
 * of a spreadsheet keeps real numbers and dates with a number format.)
 */
final class ReportFormatter
{
    public static function format(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($type) {
            ReportColumn::MONEY => '₱'.number_format((float) $value, 2),
            ReportColumn::INTEGER => number_format((float) $value, 0),
            ReportColumn::DECIMAL => rtrim(rtrim(number_format((float) $value, 2), '0'), '.'),
            ReportColumn::PERCENT => number_format((float) $value, 1).'%',
            ReportColumn::DATE => CarbonImmutable::parse((string) $value)->format('j M Y'),
            ReportColumn::DATETIME => CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'))->format('j M Y, g:i a'),
            default => (string) $value,
        };
    }

    /**
     * A name for the downloaded file: "stocksense-sales-2026-09-06_2026-10-05.xlsx".
     */
    public static function fileName(Report $report, ReportFilters $filters, string $extension): string
    {
        $period = in_array('date', $report->filters(), true)
            ? $filters->from->toDateString().'_'.$filters->to->toDateString()
            : CarbonImmutable::today()->toDateString();

        return "stocksense-{$report->key()}-{$period}.{$extension}";
    }
}
