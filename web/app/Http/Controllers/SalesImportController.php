<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Http\Requests\Sales\UpdateImportSettingsRequest;
use App\Http\Requests\Sales\UploadSalesFileRequest;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\Sales\ImportFields;
use App\Services\Sales\ImportManager;
use App\Services\Sales\ImportRowsFile;
use App\Services\Sales\InvalidImportFile;
use App\Services\Sales\SalesImportPreview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing sales from a CSV or Excel file: upload, check the column mapping,
 * confirm, watch progress, download the error report, undo. Needs
 * `sales.import` (see routes/web.php).
 */
class SalesImportController extends Controller
{
    public function __construct(private readonly ImportManager $imports) {}

    public function index(): Response
    {
        return Inertia::render('sales/imports/index', [
            'batches' => ImportBatch::query()
                ->with('user:id,name')
                ->latest('id')
                ->paginate(15)
                ->through(fn (ImportBatch $batch) => $this->present($batch)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('sales/imports/create', [
            'maxMegabytes' => config('imports.max_file_kb') / 1024,
            'maxRows' => config('imports.max_rows'),
        ]);
    }

    /**
     * A small example file with the right column names.
     */
    public function template(): StreamedResponse
    {
        $skus = Product::query()->orderBy('sku')->limit(2)->pluck('sku')->all() + [0 => 'SKU-001', 1 => 'SKU-002'];

        return response()->streamDownload(function () use ($skus) {
            $out = new SplFileObject('php://output', 'w');
            $out->fputcsv(['date', 'sku', 'quantity', 'unit_price']);
            $out->fputcsv([now()->subDay()->toDateString(), $skus[0], 12, '85.00']);
            $out->fputcsv([now()->subDay()->toDateString(), $skus[1], 3, '']);
        }, 'sales-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function store(UploadSalesFileRequest $request): RedirectResponse
    {
        try {
            $batch = $this->imports->start(
                $request->file('file'),
                $request->boolean('adjust_stock'),
                $request->user(),
            );
        } catch (InvalidImportFile $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return to_route('sales.imports.show', $batch);
    }

    public function show(ImportBatch $batch, SalesImportPreview $preview): Response
    {
        $props = ['batch' => $this->present($batch->load('user:id,name'))];

        if ($batch->status === ImportStatus::Preview) {
            $props['preview'] = $preview->build($batch);
            $props['fields'] = collect(ImportFields::all())
                ->map(fn (array $field, string $key) => ['key' => $key, 'label' => $field['label'], 'required' => $field['required']])
                ->values()
                ->all();
            $props['dateFormats'] = collect(ImportFields::DATE_FORMATS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all();
        }

        return Inertia::render('sales/imports/show', $props);
    }

    public function update(UpdateImportSettingsRequest $request, ImportBatch $batch): RedirectResponse
    {
        $this->requireStatus($batch, ImportStatus::Preview);

        $columns = [];
        foreach (array_keys(ImportFields::all()) as $field) {
            $value = $request->validated("columns.{$field}");
            $columns[$field] = $value === null ? null : (int) $value;
        }

        $batch->forceFill(['settings' => [
            ...$batch->settings,
            'columns' => $columns,
            'date_format' => $request->validated('date_format'),
            'adjust_stock' => $request->boolean('adjust_stock'),
        ]])->save();

        return back();
    }

    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->requireStatus($batch, ImportStatus::Preview);

        $this->imports->confirm($batch, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import started.']);

        return back();
    }

    public function cancel(ImportBatch $batch): RedirectResponse
    {
        $this->requireStatus($batch, ImportStatus::Preview);

        $this->imports->cancel($batch);

        return to_route('sales.imports.index');
    }

    public function undo(Request $request, ImportBatch $batch): RedirectResponse
    {
        if (! $batch->status->canUndo()) {
            throw ValidationException::withMessages(['import' => 'This import cannot be undone.']);
        }

        $this->imports->undo($batch, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import undone. Its sales were removed and stock put back.']);

        return back();
    }

    /**
     * Every row that failed, as a CSV the person can fix and upload again.
     */
    public function errors(ImportBatch $batch): StreamedResponse
    {
        $file = new ImportRowsFile($batch);

        abort_unless($file->hasErrors(), 404);

        $headers = $batch->settings['headers'];

        return response()->streamDownload(function () use ($file, $headers) {
            $out = new SplFileObject('php://output', 'w');
            $out->fputcsv(['Row', 'Problem', ...$headers]);

            foreach ($file->errors() as $failed) {
                $out->fputcsv([$failed['row'], implode(' ', $failed['messages']), ...$failed['cells']]);
            }
        }, "import-{$batch->id}-errors.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * Guards against acting on an import that has moved on (a second click on
     * "Import", say). Answers with a message to show, not an error page.
     *
     * @throws ValidationException
     */
    private function requireStatus(ImportBatch $batch, ImportStatus $status): void
    {
        if ($batch->status !== $status) {
            throw ValidationException::withMessages(['import' => 'This import has already moved on.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'filename' => $batch->filename,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'is_active' => $batch->status->isActive(),
            'can_undo' => $batch->status->canUndo(),
            'rows_total' => $batch->rows_total,
            'rows_processed' => $batch->rows_processed,
            'rows_ok' => $batch->rows_ok,
            'rows_duplicate' => $batch->rows_duplicate,
            'rows_failed' => $batch->rows_failed,
            'errors' => $batch->errors ?? [],
            'has_error_report' => $batch->rows_failed > 0,
            'error_message' => $batch->error_message,
            'adjust_stock' => $batch->settings['adjust_stock'],
            'headers' => $batch->settings['headers'],
            'columns' => $batch->settings['columns'],
            'date_format' => $batch->settings['date_format'],
            'user' => $batch->user?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
        ];
    }
}
