<?php

namespace App\Services\Reporting\Reports;

/**
 * What a report found: the table, with a few headline figures above it and an
 * optional totals row below. Values are raw (numbers, ISO dates); how they are
 * written is up to the screen, the spreadsheet or the PDF, using the columns.
 */
final class ReportResult
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows  Each keyed by column key
     * @param  list<array{label: string, value: mixed, type: string}>  $summary  Headline figures
     * @param  array<string, mixed>  $totals  A totals row, keyed by column key; empty for none
     * @param  list<string>  $notes  Things the reader should know to read it right
     */
    public function __construct(
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $summary = [],
        public readonly array $totals = [],
        public readonly array $notes = [],
    ) {}
}
