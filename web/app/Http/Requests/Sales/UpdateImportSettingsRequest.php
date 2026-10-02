<?php

namespace App\Http\Requests\Sales;

use App\Models\ImportBatch;
use App\Services\Sales\ImportFields;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which column of the file is which, the date layout, and whether stock moves.
 */
class UpdateImportSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var ImportBatch $batch */
        $batch = $this->route('batch');
        $lastColumn = max(0, count($batch->settings['headers']) - 1);

        $rules = [
            'date_format' => ['required', Rule::in(array_keys(ImportFields::DATE_FORMATS))],
            'adjust_stock' => ['required', 'boolean'],
        ];

        foreach (array_keys(ImportFields::all()) as $field) {
            // A column that has not been chosen arrives as an empty value.
            $rules["columns.{$field}"] = ['nullable', 'integer', 'min:0', "max:{$lastColumn}"];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date_format' => 'date format',
            'columns.date' => 'date column',
            'columns.sku' => 'SKU column',
            'columns.quantity' => 'quantity column',
            'columns.unit_price' => 'unit price column',
        ];
    }
}
