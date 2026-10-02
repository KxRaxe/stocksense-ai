<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Used for both creating and editing a category; on edit the route carries the
 * category being changed.
 */
class CategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            'name' => [
                'required', 'string', 'max:100',
                // Names are unique regardless of capitals: "Hardware" and "hardware" clash.
                function (string $attribute, mixed $value, Closure $fail) use ($category) {
                    $taken = Category::query()
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($category, fn ($query) => $query->whereKeyNot($category->getKey()))
                        ->exists();

                    if ($taken) {
                        $fail('A category with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'service_level' => ['required', 'numeric', 'min:50', 'max:99.9'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['service_level' => 'service level'];
    }
}
