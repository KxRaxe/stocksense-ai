<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\SettingsSchema;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The system settings form: every setting, each checked by its own rules.
 * Who may submit it is decided by the route (`settings.manage`).
 */
class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = ['values' => ['required', 'array']];

        foreach (SettingsSchema::definitions() as $key => $definition) {
            $rules["values.{$key}"] = $definition->rules();
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SettingsSchema::definitions() as $key => $definition) {
            $attributes["values.{$key}"] = mb_strtolower($definition->label);
        }

        return $attributes;
    }

    /**
     * The digest summarises the advice, so it must go out after the advice is refreshed.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $advice = $this->input('values.advice_time');
                $digest = $this->input('values.digest_time');

                if ($validator->errors()->hasAny(['values.advice_time', 'values.digest_time']) || ! is_string($advice) || ! is_string($digest)) {
                    return;
                }

                if ($digest <= $advice) {
                    $validator->errors()->add('values.digest_time', 'The digest must be sent after the recommendations are refreshed, so it is up to date.');
                }
            },
        ];
    }
}
