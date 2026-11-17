<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StockOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockOperationCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason', 'reference', 'invoice_number'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $action = \App\Services\StockOperations::action($this);
        $rules = ['reason' => ['required','string','min:5','max:2000'],
            'current_password' => ['sometimes','required','string','max:1024']];
        if ($action !== 'store') {
            $rules['version'] = ['required','integer','min:1','max:4294967293'];
        }
        if (! in_array($action, ['store','update'], true)) {
            if (in_array($action, ['approve','reject','cancel'], true)) {
                $rules['current_password'][0] = 'required';
            }

            return $rules;
        }
        $id = ['integer','min:1','max:999999999999999999'];
        $version = ['integer','min:1','max:4294967294'];
        return $rules + ['kind' => ['required', Rule::in(StockOperation::KINDS)],
            'source_store_id' => ['sometimes','nullable',...$id], 'destination_store_id' => ['sometimes','nullable',...$id],
            'source_store_version' => ['sometimes','nullable',...$version], 'destination_store_version' => ['sometimes','nullable',...$version],
            'supplier_id' => ['sometimes','nullable',...$id], 'supplier_version' => ['sometimes','nullable',...$version],
            'invoice_number' => ['sometimes','nullable','string','min:1','max:128'],
            'currency' => ['sometimes','nullable','string','regex:/^[A-Z]{3}$/D'], 'reference' => ['required','string','min:1','max:128'],
            'items' => ['required','array','list','min:1','max:50'],
            'items.*' => ['required','array:batch_id,drug_version,pack_id,pack_count,quantity,unit_cost,source_balance_version'],
            'items.*.batch_id' => ['required',...$id,'distinct:strict'], 'items.*.drug_version' => ['required',...$version],
            'items.*.pack_id' => ['sometimes','nullable',...$id], 'items.*.pack_count' => ['sometimes','string','max:16'],
            'items.*.quantity' => ['sometimes','string','max:16'], 'items.*.unit_cost' => ['sometimes','nullable','string','max:16'],
            'items.*.source_balance_version' => ['sometimes','integer','min:0','max:999999999999999999']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
            if (in_array(\App\Services\StockOperations::action($this), ['store','update'], true)) {
                foreach ((array) $this->input('items', []) as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $pack = isset($item['pack_id']);
                    if ($pack && (! isset($item['pack_count']) || array_key_exists('quantity', $item))
                        || ! $pack && (! isset($item['quantity']) || array_key_exists('pack_count', $item))) {
                        $validator->errors()->add('items.'.$index.'.quantity', 'Supply either a base quantity or a pack and its count.');
                    }
                }
            }
        }];
    }
}
