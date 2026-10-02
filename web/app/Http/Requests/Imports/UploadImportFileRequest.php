<?php

namespace App\Http\Requests\Imports;

use App\Http\Controllers\ImportController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The file, and the choices its kind of import asks for at upload.
 */
class UploadImportFileRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        /** @var ImportController $controller */
        $controller = $this->route()?->getController();

        return [
            'file' => ['required', 'file', 'extensions:csv,txt,xlsx', 'max:'.config('imports.max_file_kb')],
            ...$controller->type()->definition()->uploadRules(),
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
