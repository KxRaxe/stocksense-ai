<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Used for both creating and editing a product. Opening stock can only be
 * given when creating; afterwards stock changes go through restocks and
 * stock-takes so the ledger stays complete.
 */
class ProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // SKUs are stored upper-case, so compare them that way.
        $this->merge(['sku' => Str::upper(trim((string) $this->input('sku')))]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        $rules = [
            'sku' => [
                'required', 'string', 'max:64',
                Rule::unique(Product::class, 'sku')->ignore($product?->getKey()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'unit' => ['required', 'string', 'max:20'],
            'unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'moq' => ['required', 'integer', 'min:1', 'max:1000000'],
            'pack_size' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reorder_point_override' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'safety_stock_override' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ];

        if ($product === null) {
            $rules['opening_stock'] = ['nullable', 'integer', 'min:0', 'max:10000000'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'category_id' => 'category',
            'unit_cost' => 'unit cost',
            'unit_price' => 'unit price',
            'lead_time_days' => 'lead time',
            'moq' => 'minimum order quantity',
            'pack_size' => 'pack size',
            'reorder_point_override' => 'reorder point',
            'safety_stock_override' => 'safety stock',
            'opening_stock' => 'opening stock',
        ];
    }
}
