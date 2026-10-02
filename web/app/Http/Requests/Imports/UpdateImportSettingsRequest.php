<?php

namespace App\Http\Requests\Imports;

use App\Http\Controllers\ImportController;
use App\Models\ImportBatch;
use App\Services\Imports\ImportDefinition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which column of the file is which, and the other choices for this kind of
 * import (date layout, what to do with products that already exist, ...).
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
        $definition = $this->definition();
        $lastColumn = max(0, count($batch->settings['headers']) - 1);

        $rules = $definition->settingsRules();

        foreach (array_keys($definition->fields()) as $field) {
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
        $attributes = ['date_format' => 'date format'];

        foreach ($this->definition()->fields() as $field => $details) {
            // "SKU (product code)" reads better as "SKU column" in an error message.
            $attributes["columns.{$field}"] = trim(preg_replace('/\s*\(.*\)/', '', $details['label']) ?? $details['label']).' column';
        }

        return $attributes;
    }

    private function definition(): ImportDefinition
    {
        /** @var ImportController $controller */
        $controller = $this->route()?->getController();

        return $controller->type()->definition();
    }
}
