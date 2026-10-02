<?php

namespace App\Services\Reporting\Reports\Exports;

use App\Services\Reporting\Reports\ReportColumn;
use App\Services\Reporting\Reports\ReportResult;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The report's table as a worksheet: a heading row, then real numbers and real
 * dates with number formats (so a person can sort, filter and add them up),
 * and the totals row if there is one.
 */
class ReportDataSheet implements FromArray, ShouldAutoSize, WithColumnFormatting, WithFreezePane, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        private readonly string $title,
        private readonly ReportResult $result,
    ) {}

    public function title(): string
    {
        // Sheet names are limited to 31 characters and cannot contain these.
        return mb_substr(str_replace(['\\', '/', '*', '?', ':', '[', ']'], ' ', $this->title), 0, 31);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(fn (ReportColumn $column) => $column->label, $this->result->columns);
    }

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $lines = array_map(fn (array $row) => $this->line($row), $this->result->rows);

        if ($this->result->totals !== []) {
            $lines[] = $this->line($this->result->totals);
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        $formats = [];

        foreach ($this->result->columns as $index => $column) {
            $format = match ($column->type) {
                ReportColumn::MONEY => '"₱"#,##0.00',
                ReportColumn::INTEGER => '#,##0',
                ReportColumn::DECIMAL => '#,##0.0#',
                ReportColumn::PERCENT => '0.0"%"',
                ReportColumn::DATE => 'yyyy-mm-dd',
                ReportColumn::DATETIME => 'yyyy-mm-dd hh:mm',
                default => null,
            };

            if ($format !== null) {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = $format;
            }
        }

        return $formats;
    }

    public function freezePane(): string
    {
        return 'A2';
    }

    /**
     * @return array<int|string, mixed>
     */
    public function styles(Worksheet $sheet): array
    {
        $last = Coordinate::stringFromColumnIndex(max(count($this->result->columns), 1));

        $sheet->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
        ]);

        if ($this->result->totals !== []) {
            $row = count($this->result->rows) + 2;

            $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
        }

        return [];
    }

    /**
     * One row's values in column order, dates as Excel dates and gaps as empty cells.
     *
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function line(array $row): array
    {
        $line = [];

        foreach ($this->result->columns as $column) {
            $value = $row[$column->key] ?? null;

            $line[] = match (true) {
                $value === null => null,
                $column->type === ReportColumn::DATE => Date::PHPToExcel(CarbonImmutable::parse((string) $value)->startOfDay()),
                $column->type === ReportColumn::DATETIME => Date::PHPToExcel(CarbonImmutable::parse((string) $value)->setTimezone((string) config('app.timezone'))->toDateTime()),
                default => $value,
            };
        }

        return $line;
    }
}
