<?php

namespace App\Services\Reporting\Reports\Exports;

use App\Services\Reporting\Reports\ReportFormatter;
use App\Services\Reporting\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * An "About" sheet: what the report is, what it was narrowed to, when it was
 * made, its headline figures and the notes needed to read it correctly.
 */
class ReportSummarySheet extends SafeValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithStyles, WithTitle
{
    public function __construct(
        private readonly string $title,
        private readonly string $filters,
        private readonly string $generatedAt,
        private readonly ReportResult $result,
    ) {}

    public function title(): string
    {
        return 'About';
    }

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        $lines = [
            ['StockSense AI', $this->title],
            ['Covers', $this->filters === '' ? 'Everything' : $this->filters],
            ['Made', $this->generatedAt],
            [],
        ];

        foreach ($this->result->summary as $figure) {
            $lines[] = [$figure['label'], ReportFormatter::format($figure['value'], $figure['type'])];
        }

        if ($this->result->notes !== []) {
            $lines[] = [];
            $lines[] = ['Notes'];

            foreach ($this->result->notes as $note) {
                $lines[] = [$note];
            }
        }

        return $lines;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true, 'size' => 13]]];
    }
}
