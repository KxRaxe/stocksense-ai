<?php

namespace App\Services\Reporting\Reports\Exports;

use App\Services\Reporting\Reports\ReportColumn;
use App\Services\Reporting\Reports\ReportFormatter;
use App\Services\Reporting\Reports\ReportResult;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;

/**
 * A report as a PDF: landscape, with the headline figures on top, the table,
 * and the notes. Everything is written as text already, so the template only
 * lays it out.
 */
class ReportPdf
{
    /** A PDF of thousands of rows is slow to make and unreadable; the Excel export has them all. */
    public const MAX_ROWS = 1000;

    public function make(string $title, string $filters, string $generatedAt, ReportResult $result): DomPdf
    {
        return Pdf::loadView('reports.pdf', $this->data($title, $filters, $generatedAt, $result))
            ->setPaper('a4', 'landscape')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isHtml5ParserEnabled' => true, 'enableFontSubsetting' => true]);
    }

    /**
     * What the template is given: everything already written as text.
     *
     * @return array<string, mixed>
     */
    public function data(string $title, string $filters, string $generatedAt, ReportResult $result): array
    {
        return [
            'title' => $title,
            'filters' => $filters,
            'generatedAt' => $generatedAt,
            'summary' => array_map(fn (array $figure) => [
                'label' => $figure['label'],
                'text' => ReportFormatter::format($figure['value'], $figure['type']),
            ], $result->summary),
            'columns' => array_map(fn (ReportColumn $column) => [
                'key' => $column->key,
                'label' => $column->label,
                'numeric' => $column->isNumeric(),
            ], $result->columns),
            'rows' => array_map(fn (array $row) => $this->line($result, $row), array_slice($result->rows, 0, self::MAX_ROWS)),
            'totals' => $result->totals === [] ? null : $this->line($result, $result->totals, blank: ''),
            'truncated' => count($result->rows) > self::MAX_ROWS ? ['shown' => self::MAX_ROWS, 'total' => count($result->rows)] : null,
            'notes' => $result->notes,
        ];
    }

    /**
     * One row's values written as text, by column key.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function line(ReportResult $result, array $row, string $blank = '-'): array
    {
        $line = [];

        foreach ($result->columns as $column) {
            $value = $row[$column->key] ?? null;

            $line[$column->key] = $value === null || $value === '' ? $blank : ReportFormatter::format($value, $column->type);
        }

        return $line;
    }
}
