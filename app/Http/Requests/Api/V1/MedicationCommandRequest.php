<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Services\MedicationFulfillment;

class MedicationCommandRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];
        $version = ['required', 'integer', 'min:1', 'max:4294967293'];
        $rules = ['encounter_version' => $version, 'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'current_password' => ['required', 'string', 'max:1024']];
        $action = MedicationFulfillment::action($this);
        if ($action === 'authorize') {
            return $rules + ['prescription_item_id' => $id, 'prescription_version' => $version, 'drug_id' => $id,
                'drug_version' => $version, 'assignment_id' => $id, 'assignment_version' => $version,
                'maximum_quantity' => ['required', 'string', 'max:16']];
        }
        $rules['version'] = $version;
        if ($action === 'dispense') {
            return $rules + ['quantity' => ['required', 'string', 'max:16'], 'batch_id' => $id, 'store_id' => $id,
                'store_version' => $version, 'drug_version' => $version, 'balance_version' => ['required', 'integer', 'min:0', 'max:999999999999999999']];
        }
        $rules['dispense_id'] = $id;
        if (in_array($action, ['return-approve', 'return-reject'], true)) {
            return $rules + ['return_id' => $id];
        }
        $rules['quantity'] = ['required', 'string', 'max:16'];
        if ($action === 'return-request') {
            return $rules + ['disposition' => ['required', Rule::in(['usable', 'damaged', 'expired'])], 'usable_confirmed' => ['required', 'boolean']];
        }
        $rules['observed_at'] = ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', 'date'];
        if ($action === 'correct') {
            $rules['administration_id'] = $id;
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
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
