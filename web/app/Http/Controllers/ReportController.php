<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\Reporting\Reports\Exports\ReportPdf;
use App\Services\Reporting\Reports\Exports\ReportWorkbook;
use App\Services\Reporting\Reports\Report;
use App\Services\Reporting\Reports\ReportFilters;
use App\Services\Reporting\Reports\ReportFormatter;
use App\Services\Reporting\Reports\ReportRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Response as ResponseFactory;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Reports: the list of those a person may open, each one on screen with its
 * filters, and each as an Excel or PDF download. What a person may open
 * depends on their role (`reports.view`, or `reports.inventory` for stock only),
 * so every action checks the report's own permission.
 */
class ReportController extends Controller
{
    public const PER_PAGE = 25;

    public function __construct(private readonly ReportRegistry $registry) {}

    public function index(Request $request): Response
    {
        $available = $this->registry->availableTo($request->user());

        abort_if($available->isEmpty(), 403);

        return Inertia::render('reports/index', [
            'reports' => $available->map(fn (Report $report) => [
                'key' => $report->key(),
                'title' => $report->title(),
                'description' => $report->description(),
                'filters' => $report->filters(),
            ])->values()->all(),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $definition = $this->authorized($request, $report);
        [$filters, $errors] = ReportFilters::fromInput($request->query(), $definition);

        $result = $definition->run($filters);

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            array_slice($result->rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($result->rows),
            self::PER_PAGE,
            $page,
            ['path' => route('reports.show', $definition->key()), 'query' => $request->except('page')],
        );

        return Inertia::render('reports/show', [
            'report' => [
                'key' => $definition->key(),
                'title' => $definition->title(),
                'description' => $definition->description(),
                'filters' => $definition->filters(),
            ],
            'reports' => $this->registry->availableTo($request->user())
                ->map(fn (Report $other) => ['key' => $other->key(), 'title' => $other->title()])
                ->values()
                ->all(),
            'filters' => [
                'from' => $filters->from->toDateString(),
                'to' => $filters->to->toDateString(),
                'category' => $filters->categoryId,
                'granularity' => $filters->granularity->value,
            ],
            'filter_errors' => $errors,
            'subtitle' => $filters->describe($definition),
            'columns' => array_map(fn ($column) => $column->toArray(), $result->columns),
            'rows' => $paginator,
            'summary' => $result->summary,
            'totals' => $result->totals === [] ? null : $result->totals,
            'notes' => $result->notes,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'exports' => array_map(fn (string $format, string $label) => [
                'format' => $format,
                'label' => $label,
                'url' => route('reports.export', [$definition->key(), $format, ...$filters->toQuery($definition)]),
            ], ['xlsx', 'pdf'], ['Excel', 'PDF']),
        ]);
    }

    public function export(Request $request, string $report, string $format): HttpResponse|BinaryFileResponse
    {
        $definition = $this->authorized($request, $report);
        [$filters] = ReportFilters::fromInput($request->query(), $definition);

        $result = $definition->run($filters);
        $now = CarbonImmutable::now();
        $generatedAt = $now->setTimezone((string) config('app.timezone'))->format('j M Y, g:i a');
        $subtitle = $filters->describe($definition);
        $fileName = ReportFormatter::fileName($definition, $filters, $format);

        activity('reports')
            ->causedBy($request->user())
            ->withProperties(['report' => $definition->key(), 'format' => $format, 'filters' => $filters->toQuery($definition), 'rows' => count($result->rows)])
            ->log('Exported the '.mb_strtolower($definition->title()).' report as '.($format === 'pdf' ? 'a PDF' : 'an Excel file'));

        if ($format === 'pdf') {
            return ResponseFactory::make(
                app(ReportPdf::class)->make($definition->title(), $subtitle, $generatedAt, $result)->output(),
                200,
                ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$fileName.'"'],
            );
        }

        return Excel::download(new ReportWorkbook($definition->title(), $subtitle, $generatedAt, $result), $fileName, ExcelWriter::XLSX);
    }

    /**
     * Finds the report, or 404, and 403 unless this person may open it.
     */
    private function authorized(Request $request, string $key): Report
    {
        $report = $this->registry->find($key) ?? abort(404);

        abort_unless($request->user()->can($report->permission()->value), 403);

        return $report;
    }
}
