<?php

namespace App\Services\Reporting\Reports\Exports;

use App\Services\Reporting\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * A report as an Excel file: the table on the first sheet, kept clean so it can
 * be sorted and filtered, and an "About" sheet with the context.
 */
class ReportWorkbook implements Export, WithMultipleSheets
{
    public function __construct(
        private readonly string $title,
        private readonly string $filters,
        private readonly string $generatedAt,
        private readonly ReportResult $result,
    ) {}

    /**
     * @return list<object>
     */
    public function sheets(): array
    {
        return [
            new ReportDataSheet($this->title, $this->result),
            new ReportSummarySheet($this->title, $this->filters, $this->generatedAt, $this->result),
        ];
    }
}
