<?php

namespace App\Http\Requests\Api\V1;

use App\Services\InvestigationRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvestigationCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'specimen_ref', 'clinical_context'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $version = ['required', 'integer', 'min:1', 'max:4294967294'];
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];
        $rules = ['encounter_version' => $version, 'reason' => ['required', 'string', 'min:5', 'max:2000']];
        if (InvestigationRequests::action($this) === 'store') {
            return $rules + ['patient_version' => $version, 'assignment_id' => $id, 'assignment_version' => $version,
                'clinical_context' => ['sometimes', 'nullable', 'string', 'max:20000'],
                'items' => ['required', 'array', 'list', 'min:1', 'max:20'],
                'items.*' => ['required', 'array:test_id,schema_id,test_version,priority'],
                'items.*.test_id' => [...$id, 'distinct'], 'items.*.schema_id' => $id, 'items.*.test_version' => $version,
                'items.*.priority' => ['required', Rule::in(['routine', 'urgent'])]];
        }

        return $rules + ['order_version' => $version, 'version' => $version]
            + (InvestigationRequests::action($this) === 'collect' ? ['specimen_ref' => ['required', 'string', 'max:191']] : []);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
        }];
    }
}
