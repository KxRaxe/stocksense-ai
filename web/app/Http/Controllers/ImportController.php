<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Http\Requests\Imports\UpdateImportSettingsRequest;
use App\Http\Requests\Imports\UploadImportFileRequest;
use App\Models\ImportBatch;
use App\Services\Imports\ImportManager;
use App\Services\Imports\ImportRowsFile;
use App\Services\Imports\InvalidImportFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Importing from a CSV or Excel file: upload, check the column mapping,
 * confirm, watch progress, download the error report, undo. The steps are the
 * same for every kind of file; a subclass says which kind it handles, and the
 * kind's ImportDefinition supplies the rest. Who may use each is set where its
 * routes are declared (routes/web.php).
 */
abstract class ImportController extends Controller
{
    public function __construct(private readonly ImportManager $imports) {}

    abstract public function type(): ImportType;

    public function index(): Response
    {
        return Inertia::render($this->page('index'), [
            'batches' => ImportBatch::query()
                ->where('type', $this->type())
                ->with('user:id,name')
                ->latest('id')
                ->paginate(15)
                ->through(fn (ImportBatch $batch) => $this->present($batch)),
            'links' => ['create' => $this->link('create')],
        ]);
    }

    public function create(): Response
    {
        $definition = $this->type()->definition();

        return Inertia::render($this->page('create'), [
            'maxMegabytes' => config('imports.max_file_kb') / 1024,
            'maxRows' => config('imports.max_rows'),
            'options' => array_values(array_filter($definition->options(), fn (array $option) => $option['upload'])),
            'links' => [
                'store' => $this->link('store'),
                'template' => $this->link('template'),
                'index' => $this->link('index'),
            ],
        ]);
    }

    /**
     * A small example file with the right column names.
     */
    public function template(): StreamedResponse
    {
        $rows = $this->type()->definition()->template();

        return response()->streamDownload(function () use ($rows) {
            $out = new SplFileObject('php://output', 'w');

            foreach ($rows as $row) {
                $out->fputcsv($row);
            }
        }, "{$this->type()->routePrefix()}-import-template.csv", ['Content-Type' => 'text/csv']);
    }

    public function store(UploadImportFileRequest $request): RedirectResponse
    {
        $input = $request->safe()->except('file');

        try {
            $batch = $this->imports->start($this->type(), $request->file('file'), $input, $request->user());
        } catch (InvalidImportFile $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return to_route("{$this->type()->routePrefix()}.imports.show", $batch);
    }

    public function show(ImportBatch $batch): Response
    {
        $this->own($batch);

        $definition = $this->type()->definition();
        $props = ['batch' => $this->present($batch->load('user:id,name'), detailed: true)];

        if ($batch->status === ImportStatus::Preview) {
            $props['preview'] = $definition->preview($batch);
            $props['fields'] = collect($definition->fields())
                ->map(fn (array $field, string $key) => ['key' => $key, 'label' => $field['label'], 'required' => $field['required']])
                ->values()
                ->all();
            $props['options'] = $definition->options();
        }

        return Inertia::render($this->page('show'), $props);
    }

    public function update(UpdateImportSettingsRequest $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($batch);
        $this->requireStatus($batch, ImportStatus::Preview);

        $definition = $this->type()->definition();

        $columns = [];
        foreach (array_keys($definition->fields()) as $field) {
            $value = $request->validated("columns.{$field}");
            $columns[$field] = $value === null ? null : (int) $value;
        }

        $batch->forceFill(['settings' => [
            ...$batch->settings,
            'columns' => $columns,
            ...$definition->updatedOptions($request->validated()),
        ]])->save();

        return back();
    }

    public function confirm(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($batch);
        $this->requireStatus($batch, ImportStatus::Preview);

        $this->imports->confirm($batch, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import started.']);

        return back();
    }

    public function cancel(ImportBatch $batch): RedirectResponse
    {
        $this->own($batch);
        $this->requireStatus($batch, ImportStatus::Preview);

        $this->imports->cancel($batch);

        return redirect($this->link('index'));
    }

    public function undo(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($batch);

        if (! $batch->status->canUndo()) {
            throw ValidationException::withMessages(['import' => 'This import cannot be undone.']);
        }

        $this->imports->undo($batch, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Import undone.']);

        return back();
    }

    /**
     * Every row that failed, as a CSV the person can fix and upload again.
     */
    public function errors(ImportBatch $batch): StreamedResponse
    {
        $this->own($batch);

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
     * An import belongs to the kind of file it was made from. Opening it through
     * another kind's address would skip that kind's permission, so it is not found.
     */
    private function own(ImportBatch $batch): void
    {
        abort_unless($batch->type === $this->type(), 404);
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

    private function page(string $name): string
    {
        return "{$this->type()->routePrefix()}/imports/{$name}";
    }

    /**
     * The address of one of this kind's import routes.
     *
     * @param  array<string, mixed>|ImportBatch  $parameters
     */
    private function link(string $name, array|ImportBatch $parameters = []): string
    {
        return route("{$this->type()->routePrefix()}.imports.{$name}", $parameters, absolute: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ImportBatch $batch, bool $detailed = false): array
    {
        $definition = $batch->type->definition();

        $links = [
            'show' => $this->link('show', $batch),
            'update' => $this->link('update', $batch),
            'confirm' => $this->link('confirm', $batch),
            'cancel' => $this->link('cancel', $batch),
            'undo' => $this->link('undo', $batch),
            'errors' => $this->link('errors', $batch),
            'index' => $this->link('index'),
        ];

        $presented = [
            'id' => $batch->id,
            'filename' => $batch->filename,
            'status' => $batch->status->value,
            'status_label' => $batch->status->label(),
            'is_active' => $batch->status->isActive(),
            'can_undo' => $batch->status->canUndo() && $definition->hasUndoableChanges($batch),
            'rows_total' => $batch->rows_total,
            'rows_processed' => $batch->rows_processed,
            'rows_ok' => $batch->rows_ok,
            'rows_duplicate' => $batch->rows_duplicate,
            'rows_updated' => $batch->rows_updated,
            'rows_failed' => $batch->rows_failed,
            'errors' => $batch->errors ?? [],
            'has_error_report' => $batch->rows_failed > 0,
            'error_message' => $batch->error_message,
            'headers' => $batch->settings['headers'],
            'columns' => $batch->settings['columns'],
            'options' => $definition->optionValues($batch->settings),
            'user' => $batch->user?->name,
            'created_at' => $batch->created_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'links' => $links,
        ];

        // Only the page for a single import needs these, and the list of imports
        // would otherwise run a few extra queries per row.
        if ($detailed) {
            $presented['result_figures'] = $definition->resultFigures($batch);
            $presented['undo_description'] = $definition->undoDescription($batch);
            $presented['results'] = $batch->status !== ImportStatus::Undone && $definition->hasUndoableChanges($batch)
                ? ['url' => $definition->resultsUrl($batch), 'label' => $definition->resultsLabel()]
                : null;
        }

        return $presented;
    }
}
