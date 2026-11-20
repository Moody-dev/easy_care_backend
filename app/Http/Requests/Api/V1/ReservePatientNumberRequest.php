<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReservePatientNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['category_id' => ['required', 'integer', 'min:1']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['category_id']) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
        }];
    }
}
