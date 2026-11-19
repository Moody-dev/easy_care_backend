<?php

namespace App\Http\Requests\Api\V1;

use App\Services\AdmissionFlow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdmissionCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'discharge_summary'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $action = AdmissionFlow::action($this);
        $version = ['required', 'integer', 'min:1', 'max:4294967294'];
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];
        $rules = ['encounter_version' => $version, 'reason' => ['required', 'string', 'min:5', 'max:2000']];
        if ($action === 'store') {
            return $rules + ['patient_version' => $version, 'identity_verified' => ['required', 'accepted']];
        }
        $rules += ['version' => $version, 'workflow_version' => ['required', 'integer', 'min:0', 'max:4294967294']];
        if (in_array($action, ['allocate', 'transfer'], true)) {
            $rules += ['bed_id' => $id, 'bed_version' => $version, 'ward_version' => $version];
        }
        if (in_array($action, ['transfer', 'release'], true)) {
            $rules += ['allocation_id' => $id, 'allocation_version' => $version];
        }
        if ($action === 'authorize-discharge') {
            $rules += ['assignment_id' => $id, 'assignment_version' => $version,
                'exit_type' => ['sometimes', Rule::in(['discharge', 'referral', 'dama'])],
                'discharge_summary' => ['nullable', 'string', 'max:20000']];
        }
        if ($action === 'complete-discharge') {
            $rules += ['allocation_id' => ['present', 'nullable', 'integer', 'min:1', 'max:999999999999999999'],
                'allocation_version' => ['present', 'nullable', 'integer', 'min:1', 'max:4294967294']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            if (AdmissionFlow::action($this) === 'complete-discharge'
                && (($this->input('allocation_id') === null) !== ($this->input('allocation_version') === null))) {
                $validator->errors()->add('allocation_id', 'Supply both allocation fields or set both to null.');
            }
            if (is_string($this->input('discharge_summary')) && strlen($this->input('discharge_summary')) > 65535) {
                $validator->errors()->add('discharge_summary', 'The summary exceeds the storage byte limit.');
            }
        }];
    }
}
