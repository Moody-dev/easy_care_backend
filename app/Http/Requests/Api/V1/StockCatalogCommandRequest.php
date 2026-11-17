<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockCatalogCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code','name','reason','approval_reference','contact_name','phone','alternate_phone','email','address','manufacturer','batch_number'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $catalog = $this->route('catalog') ?? 'store-access';
        $create = str_ends_with($this->route()->getName(), '.store');
        $id = ['integer','min:1','max:999999999999999999'];
        $text = $create ? ['required','string'] : ['sometimes','required','string'];
        $rules = ['reason' => ['required','string','min:5','max:2000'], 'current_password' => ['required','string','max:1024']];
        if ($catalog === 'store-access') {
            return $rules + ['version' => ['required','integer','min:0','max:4294967293'], 'is_active' => ['required','boolean']];
        }
        if (! $create) {
            $rules['version'] = ['required','integer','min:1','max:4294967293'];
        }
        if (in_array($catalog, ['units','drugs','suppliers','stores','packs'], true)) {
            if ($create) {
                $rules['code'] = ['required','string','min:1','max:64','regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/D'];
            }
            if ($catalog !== 'drugs' || $create) {
                $rules['name'] = [...$text,'min:1','max:191'];
            }
        }
        if ($catalog === 'drugs') {
            if ($create) {
                $rules += ['base_unit_id' => ['required',...$id], 'quantity_scale' => ['required','integer','min:0','max:6']];
                foreach (['generic_name','brand_name','strength','dosage_form','category'] as $field) {
                    $rules[$field] = ['sometimes','nullable','string','min:1','max:191'];
                }
            }
            $rules['is_active'] = ['sometimes','boolean'];
        }
        if (in_array($catalog, ['suppliers','stores'], true)) {
            $rules['is_active'] = ['sometimes','boolean'];
        }
        if ($catalog === 'suppliers') {
            foreach (['contact_name' => 191,'phone' => 32,'alternate_phone' => 32,'email' => 254,'address' => 2000] as $field => $max) {
                $rules[$field] = ['sometimes','nullable','string','min:1','max:'.$max];
            }
            $rules['email'][] = 'email';
        }
        if ($catalog === 'stores' && $create) {
            $rules += ['kind' => ['required',Rule::in(['main','dispensary','department'])], 'department_id' => ['sometimes','nullable',...$id]];
        }
        if (in_array($catalog, ['packs','prices','batches'], true)) {
            $rules['drug_id'] = ['required',...$id];
        }
        if (in_array($catalog, ['drugs','packs','prices'], true)) {
            $rules['approval_reference'] = ['required','string','min:5','max:2000'];
        }
        if ($catalog === 'packs') {
            $rules += ['expected_version' => ['required','integer','min:0','max:4294967293'], 'base_quantity' => ['required','string','max:16'], 'is_active' => ['required','boolean']];
        }
        if ($catalog === 'prices') {
            $rules += ['expected_version' => ['required','integer','min:0','max:4294967293'], 'kind' => ['required',Rule::in(['cost','selling'])],
                'currency' => ['required','string','regex:/^[A-Z]{3}$/D'], 'amount' => ['required','string','max:16'],
                'effective_at' => ['sometimes','date_format:Y-m-d\\TH:i:s.u\\Z']];
        }
        if ($catalog === 'batches') {
            $rules += ['batch_number' => ['required','string','min:1','max:128'], 'manufacturer' => ['required','string','min:1','max:191'],
                'manufactured_on' => ['sometimes','nullable','date_format:Y-m-d'],
                'expires_on' => ['required','date_format:Y-m-d',Rule::when($this->filled('manufactured_on'), 'after_or_equal:manufactured_on')]];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
        }];
    }
}
