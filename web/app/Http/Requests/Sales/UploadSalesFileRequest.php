<?php

namespace App\Http\Requests\Sales;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UploadSalesFileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.config('imports.max_file_kb')],
            'adjust_stock' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.extensions' => 'Upload a CSV or Excel (.xlsx) file.',
            'file.max' => 'The file is too large. The most one file can be is '.(config('imports.max_file_kb') / 1024).' MB.',
        ];
    }
}
