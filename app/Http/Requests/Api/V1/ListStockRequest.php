<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['page' => ['sometimes','integer','min:1','max:1000000'],
            'per_page' => ['sometimes','integer','min:1','max:'.config('api.pagination.max_per_page')],
            'q' => ['sometimes','string','min:1','max:128'], 'active' => ['sometimes','boolean'],
            'store_id' => ['sometimes','integer','min:1','max:999999999999999999'],
            'drug_id' => ['sometimes','integer','min:1','max:999999999999999999'],
            'batch_id' => ['sometimes','integer','min:1','max:999999999999999999'],
            'kind' => ['sometimes','string','max:32'], 'status' => ['sometimes', Rule::in(['draft','submitted','posted','rejected','cancelled'])],
            'through_id' => ['sometimes','integer','min:0','max:999999999999999999'],
            'currency' => ['sometimes','string','regex:/^[A-Z]{3}$/D'], 'code' => ['sometimes','string','max:64']];
    }
}
