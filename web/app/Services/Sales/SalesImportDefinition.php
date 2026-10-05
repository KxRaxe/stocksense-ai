<?php

namespace App\Services\Sales;

use App\Enums\StockMovementType;
use App\Models\ImportBatch;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Imports\ImportDefinition;
use App\Services\Imports\ImportProcessor;
use App\Services\Inventory\StockService;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;

/**
 * Importing sales history from a CSV or Excel file: what the columns are, what
 * the person chooses, and how an import is undone. The row checks are in
 * SalesRowValidator, the saving in SalesImportProcessor.
 */
class SalesImportDefinition implements ImportDefinition
{
    public function __construct(
        private readonly SalesImportProcessor $processor,
        private readonly SalesImportPreview $preview,
        private readonly StockService $stock,
    ) {}

    public function fields(): array
    {
        return SalesImportFields::all();
    }

    public function options(): array
    {
        return [
            [
                'name' => 'adjust_stock',
                'label' => 'Should these sales change stock levels?',
                'kind' => 'radio',
                'upload' => true,
                'choices' => [
                    [
                        'value' => '0',
                        'label' => 'No, this is past sales history',
                        'help' => 'Your current stock count already reflects these sales. Use this for old data, so the forecasts have history to learn from.',
                    ],
                    [
                        'value' => '1',
                        'label' => 'Yes, take them off the shelf',
                        'help' => "Use this for sales that have not been taken off your stock yet, such as yesterday's export from your cash register.",
                    ],
                ],
            ],
            [
                'name' => 'date_format',
                'label' => 'How are dates written?',
                'kind' => 'select',
                'upload' => false,
                'choices' => array_map(
                    fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                    array_keys(SalesImportFields::DATE_FORMATS),
                    SalesImportFields::DATE_FORMATS,
                ),
            ],
        ];
    }

    public function uploadRules(): array
    {
        return ['adjust_stock' => ['required', 'boolean']];
    }

    public function settingsRules(): array
    {
        return [
            'date_format' => ['required', Rule::in(array_keys(SalesImportFields::DATE_FORMATS))],
            'adjust_stock' => ['required', 'boolean'],
        ];
    }

    public function initialOptions(array $input): array
    {
        return [
            'date_format' => 'iso',
            'adjust_stock' => filter_var($input['adjust_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function updatedOptions(array $validated): array
    {
        return [
            'date_format' => $validated['date_format'],
            'adjust_stock' => filter_var($validated['adjust_stock'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function optionValues(array $settings): array
    {
        return [
            'date_format' => $settings['date_format'],
            'adjust_stock' => $settings['adjust_stock'] ? '1' : '0',
        ];
    }

    public function processor(): ImportProcessor
    {
        return $this->processor;
    }

    public function preview(ImportBatch $batch): array
    {
        return $this->preview->build($batch);
    }

    public function resultFigures(ImportBatch $batch): array
    {
        return [
            ['label' => 'Rows in the file', 'value' => $batch->rows_total],
            ['label' => 'Imported', 'value' => $batch->rows_ok],
            ['label' => 'Already there, skipped', 'value' => $batch->rows_duplicate],
            ['label' => 'Failed', 'value' => $batch->rows_failed],
        ];
    }

    /**
     * Removes every sale the import created and puts back the stock they took,
     * as one correcting movement per product.
     */
    public function undo(ImportBatch $batch, User $user): void
    {
        // The stock an import took off is tied to the import itself.
        $taken = StockMovement::query()
            ->where('type', StockMovementType::Sale->value)
            ->where('reference_type', $batch->getMorphClass())
            ->where('reference_id', $batch->id)
            ->selectRaw('product_id, location_id, sum(quantity) as total')
            ->groupBy('product_id', 'location_id')
            ->get();

        foreach ($taken as $line) {
            $total = (int) $line->getAttribute('total');

            if ($total === 0) {
                continue;
            }

            $this->stock->record(
                Product::findOrFail($line->product_id),
                StockMovementType::Adjustment,
                -$total,
                note: "Import #{$batch->id} undone",
                user: $user,
                reference: $batch,
                location: Location::findOrFail($line->location_id),
            );
        }

        Sale::query()->where('import_batch_id', $batch->id)->delete();
    }

    public function undoDescription(ImportBatch $batch): string
    {
        $sales = Number::format($batch->rows_ok);
        $stock = $batch->option('adjust_stock') ? ' and puts their stock back' : '';

        return "This removes the {$sales} sales it brought in{$stock}. The change is recorded. You can upload the file again afterwards.";
    }

    public function hasUndoableChanges(ImportBatch $batch): bool
    {
        return $batch->rows_ok > 0;
    }

    public function template(): array
    {
        $skus = Product::query()->orderBy('sku')->limit(2)->pluck('sku')->all() + [0 => 'SKU-001', 1 => 'SKU-002'];
        $yesterday = now()->subDay()->toDateString();

        return [
            ['date', 'sku', 'quantity', 'unit_price'],
            [$yesterday, $skus[0], 12, '85.00'],
            [$yesterday, $skus[1], 3, ''],
        ];
    }

    public function resultsUrl(ImportBatch $batch): string
    {
        return route('sales.index', ['import' => $batch->id]);
    }

    public function resultsLabel(): string
    {
        return 'View the imported sales';
    }
}
