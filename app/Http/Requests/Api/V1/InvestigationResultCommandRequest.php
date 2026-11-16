<?php

namespace App\Http\Requests\Api\V1;

use App\Services\InvestigationResults;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class InvestigationResultCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'comment'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $action = InvestigationResults::action($this);
        $version = ['required', 'integer', 'min:1', 'max:4294967294'];
        $rules = ['encounter_version' => $version, 'item_version' => $version, 'reason' => ['required', 'string', 'min:5', 'max:2000']];
        if ($action !== 'store') {
            $rules['version'] = $version;
        }
        if (in_array($action, ['store', 'update', 'amend'], true)) {
            $rules += ['fields' => ['present', 'array', 'max:50'], 'comment' => ['present', 'nullable', 'string', 'max:20000'],
                'attachment_ids' => ['present', 'array', 'max:10'], 'attachment_ids.*' => ['required', 'integer', 'min:1', 'max:999999999999999999', 'distinct']];
        }
        if (in_array($action, ['verify', 'release'], true)) {
            $rules['current_password'] = ['required', 'string', 'max:1024'];
        }
        if ($action === 'release') {
            $rules['order_version'] = $version;
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            if ($this->has('attachment_ids') && (! is_array($this->input('attachment_ids')) || ! array_is_list($this->input('attachment_ids', [])))) {
                $validator->errors()->add('attachment_ids', 'Supply a list of file IDs.');
            }
        }];
    }
}
