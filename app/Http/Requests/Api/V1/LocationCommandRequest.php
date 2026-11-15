<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class LocationCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'type', 'label'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $bed = str_contains($this->route()->getName(), '.beds.');
        $create = str_ends_with($this->route()->getName(), '.store');
        $rules = ['daily_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2']];
        $text = $create ? ['required', 'string'] : ['sometimes', 'required', 'string'];
        if ($bed) {
            $rules['label'] = [...$text, 'max:64'];
            if ($create) {
                $rules['ward_id'] = ['required', 'integer', 'min:1', 'max:999999999999999999'];
                $rules['ward_version'] = ['required', 'integer', 'min:1', 'max:4294967294'];
            }
        } else {
            $rules += ['name' => [...$text, 'max:191'], 'type' => ['sometimes', 'nullable', 'string', 'min:1', 'max:64']];
            if ($create) {
                $rules['code'] = ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'];
            }
        }
        if (! $create) {
            $rules += ['version' => ['required', 'integer', 'min:1', 'max:4294967294'], 'is_active' => ['sometimes', 'boolean']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            if (str_ends_with($this->route()->getName(), '.update')
                && array_intersect(array_keys($this->all()), ['name', 'type', 'label', 'daily_rate', 'is_active']) === []) {
                $validator->errors()->add('version', 'Supply a catalog change.');
            }
        }];
    }
}
