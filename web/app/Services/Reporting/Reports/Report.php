<?php

namespace App\Services\Reporting\Reports;

use App\Enums\Permission;
use Carbon\CarbonImmutable;

/**
 * One kind of report. Each says what it is, who may open it, which filters it
 * understands, and how to build its table. Opening and exporting go through
 * the same `run()`, so a spreadsheet always matches the screen.
 */
interface Report
{
    /** What the address calls it: "sales". */
    public function key(): string;

    public function title(): string;

    /** One sentence for the list of reports. */
    public function description(): string;

    /** What a person must be allowed to do to open it. */
    public function permission(): Permission;

    /**
     * The filters it understands, from 'date', 'category' and 'granularity'.
     *
     * @return list<string>
     */
    public function filters(): array;

    /**
     * The period shown when none is chosen. Ignored by reports with no date filter.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function defaultRange(CarbonImmutable $today): array;

    public function run(ReportFilters $filters): ReportResult;
}
