<?php

namespace App\Services\Products;

use App\Enums\ImportType;
use App\Enums\Permission;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\User;
use App\Services\Imports\ImportDefinition;
use App\Services\Imports\ImportProcessor;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Importing a product list from a CSV or Excel file: what the columns are,
 * what the person chooses, and how an import is undone. The row checks are in
 * ProductRowValidator, the saving in ProductImportProcessor.
 */
class ProductImportDefinition implements ImportDefinition
{
    private const EXISTING_SKUS = ['skip', 'update'];

    public function __construct(
        private readonly ProductImportProcessor $processor,
        private readonly ProductImportPreview $preview,
    ) {}

    public function type(): ImportType
    {
        return ImportType::Products;
    }

    public function permission(): Permission
    {
        return Permission::ManageCatalog;
    }

    public function fields(): array
    {
        return ProductImportFields::all();
    }

    public function guess(array $headers): array
    {
        return ProductImportFields::guess($headers);
    }

    public function options(): array
    {
        return [
            [
                'name' => 'existing_skus',
                'label' => 'What about SKUs that are already in your catalogue?',
                'kind' => 'radio',
                'upload' => true,
                'choices' => [
                    [
                        'value' => 'skip',
                        'label' => 'Skip them',
                        'help' => 'Leave those products exactly as they are, and add only the new ones. Safe for a file that overlaps what you already have.',
                    ],
                    [
                        'value' => 'update',
                        'label' => 'Update them',
                        'help' => "Change those products to match the file. An empty cell leaves that product's value as it is.",
                    ],
                ],
            ],
            [
                'name' => 'create_categories',
                'label' => 'Categories',
                'kind' => 'checkbox',
                'upload' => true,
                'choices' => [
                    [
                        'value' => '1',
                        'label' => "Create categories that don't exist yet",
                        'help' => 'Without this, a row whose category is not found is reported as a problem. New categories start at a '.(float) ProductImportFields::newCategoryServiceLevel().'% service level.',
                    ],
                ],
            ],
        ];
    }

    public function uploadRules(): array
    {
        return [
            'existing_skus' => ['nullable', Rule::in(self::EXISTING_SKUS)],
            'create_categories' => ['nullable', 'boolean'],
        ];
    }

    public function settingsRules(): array
    {
        return [
            'existing_skus' => ['required', Rule::in(self::EXISTING_SKUS)],
            'create_categories' => ['required', 'boolean'],
        ];
    }

    public function initialOptions(array $input): array
    {
        return [
            'existing_skus' => $input['existing_skus'] ?? 'skip',
            'create_categories' => filter_var($input['create_categories'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function updatedOptions(array $validated): array
    {
        return [
            'existing_skus' => $validated['existing_skus'],
            'create_categories' => filter_var($validated['create_categories'], FILTER_VALIDATE_BOOLEAN),
        ];
    }

    public function optionValues(array $settings): array
    {
        return [
            'existing_skus' => $settings['existing_skus'],
            'create_categories' => $settings['create_categories'] ? '1' : '0',
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
            ['label' => 'Created', 'value' => $this->created($batch)],
            ['label' => 'Updated', 'value' => $batch->rows_updated],
            ['label' => 'Already there, skipped', 'value' => $batch->rows_duplicate],
            ['label' => 'Failed', 'value' => $batch->rows_failed],
        ];
    }

    /**
     * Archives the products the import created. Products are archived, never
     * deleted, so their stock history stays complete. Changes the import made to
     * products that already existed are left as they are.
     */
    public function undo(ImportBatch $batch, User $user): void
    {
        Product::query()
            ->where('import_batch_id', $batch->id)
            ->where('is_active', true)
            ->each(fn (Product $product) => $product->update(['is_active' => false]));
    }

    public function undoDescription(ImportBatch $batch): string
    {
        $created = $this->created($batch);

        $text = 'This archives the '.Number::format($created).' '.Str::plural('product', $created).' it created. Archived products are hidden from lists, and keep their history.';

        if ($batch->rows_updated > 0) {
            $text .= ' Changes it made to the '.Number::format($batch->rows_updated).' '.Str::plural('product', $batch->rows_updated).' it updated are not reverted.';
        }

        return $text.' To bring them back, upload the file again and choose to update existing SKUs.';
    }

    public function hasUndoableChanges(ImportBatch $batch): bool
    {
        return $this->created($batch) > 0;
    }

    public function template(): array
    {
        $categories = Category::query()->orderBy('name')->limit(2)->pluck('name')->all() + [0 => 'Food & Beverages', 1 => 'Household & Cleaning'];

        return [
            ['sku', 'name', 'category', 'unit', 'unit_cost', 'unit_price', 'lead_time_days', 'moq', 'pack_size', 'reorder_point', 'safety_stock', 'opening_stock'],
            ['SKU-1001', 'Mineral water 500ml', $categories[0], 'pc', '8.00', '15.00', 5, 24, 24, 48, 24, 120],
            ['SKU-1002', 'Dishwashing liquid 1L', $categories[1], 'btl', '45.00', '72.00', 7, 12, 12, '', '', 30],
        ];
    }

    public function resultsUrl(ImportBatch $batch): string
    {
        return route('products.index', ['import' => $batch->id]);
    }

    public function resultsLabel(): string
    {
        return 'View the imported products';
    }

    /**
     * Rows that made a new product (the ones that took effect, less the updates).
     */
    private function created(ImportBatch $batch): int
    {
        return max(0, $batch->rows_ok - $batch->rows_updated);
    }
}
