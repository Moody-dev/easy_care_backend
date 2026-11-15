<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListLocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['ward_id' => ['sometimes', 'integer', 'min:1', 'max:999999999999999999'],
            'active' => ['sometimes', 'boolean'], 'available' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('api.pagination.max_per_page')]];
    }
}
