<?php

namespace App\Http\Requests\Api\V1;

use App\Services\BillingWorkflows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BillingCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $id = ['required', 'integer', 'min:1', 'max:999999999999999999'];
        $version = ['required', 'integer', 'min:0', 'max:4294967293'];
        $date = ['required', 'string', 'date', 'regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D'];
        $date[] = 'after_or_equal:1000-01-01T00:00:00Z';
        $action = BillingWorkflows::action($this);
        $rules = ['current_password' => ['required', 'string', 'max:1024'], 'reason' => ['required', 'string', 'min:5', 'max:2000']];
        if ($action === 'service') {
            return $rules + ['code' => ['required', 'string', 'regex:/^[a-z][a-z0-9._-]{0,63}$/D'], 'name' => ['required', 'string', 'max:191'],
                'category' => ['required', Rule::in(['consultation','procedure','ward','registration','other','laboratory','radiology'])]];
        }
        if ($action === 'price') {
            return $rules + ['expected_version' => $version, 'unit_price' => ['required','string','max:16'], 'is_active' => ['required','boolean'],
                'approval_reference' => ['required','string','min:5','max:2000'], 'effective_at' => ['sometimes', ...$date]];
        }
        $rules['account_version'] = $version;
        if (in_array($action, ['performed','invoice'], true)) {
            $rules += ['encounter_id' => $id, 'encounter_version' => ['required','integer','min:1','max:4294967293']];
        }
        return $rules + match ($action) {
            'performed' => ['service_id' => $id, 'quantity' => ['required','string','max:16'], 'occurred_at' => $date,
                'reference' => ['required','string','min:1','max:128'], 'performed_confirmed' => ['required','accepted']],
            'invoice' => ['self_pay_confirmed' => ['required','accepted'], 'sources' => ['required','array','list','min:1','max:50'],
                'sources.*' => ['required','array:type,id,price_id'], 'sources.*.type' => ['required',Rule::in(['performed','investigation','dispense','accommodation'])],
                'sources.*.id' => $id, 'sources.*.price_id' => $id],
            'receipt' => ['amount' => ['required','string','max:15'], 'kind' => ['required',Rule::in(['deposit','payment'])],
                'method' => ['required',Rule::in(['cash','bank_transfer','pos','other'])], 'reference' => ['sometimes','nullable','string','min:1','max:128'],
                'payer_name' => ['required','string','min:1','max:191'], 'payer_phone' => ['sometimes','nullable','string','max:32'], 'paid_at' => $date,
                'externally_confirmed' => ['required','boolean'], 'transaction_details' => ['required','array:provider,terminal_id,authorization_code,receipt_number,bank_name,method_name,notes'],
                'transaction_details.provider' => ['sometimes','string','min:1','max:191'], 'transaction_details.terminal_id' => ['sometimes','string','max:64'],
                'transaction_details.authorization_code' => ['sometimes','string','max:64'], 'transaction_details.receipt_number' => ['sometimes','string','max:128'],
                'transaction_details.bank_name' => ['sometimes','string','max:191'], 'transaction_details.method_name' => ['sometimes','string','max:191'],
                'transaction_details.notes' => ['sometimes','string','max:2000']],
            'allocate' => ['receipt_id' => $id, 'invoice_id' => $id, 'amount' => ['required','string','max:15']],
            'credit-request' => ['item_id' => $id, 'amount' => ['required','string','max:15'], 'replacement_quantity' => ['sometimes','string','max:16']],
            'refund-request' => ['receipt_id' => $id, 'amount' => ['required','string','max:15']],
            'approve' => ['payout_reference' => ['sometimes','string','min:1','max:128'], 'payout_confirmed' => ['sometimes','boolean'], 'payout_at' => ['sometimes', ...$date],
                'payout_method' => ['sometimes',Rule::in(['cash','bank_transfer','pos','other'])], 'payee_name' => ['sometimes','string','min:1','max:191'],
                'payout_details' => ['sometimes','array:provider,terminal_id,authorization_code,receipt_number,bank_name,method_name,notes'],
                'payout_details.provider' => ['sometimes','string','min:1','max:191'], 'payout_details.terminal_id' => ['sometimes','string','max:64'],
                'payout_details.authorization_code' => ['sometimes','string','max:64'], 'payout_details.receipt_number' => ['sometimes','string','max:128'],
                'payout_details.bank_name' => ['sometimes','string','max:191'], 'payout_details.method_name' => ['sometimes','string','max:191'],
                'payout_details.notes' => ['sometimes','string','max:2000']],
            default => [],
        };
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason','name','code','reference','payer_name','payout_reference','payee_name','approval_reference'] as $field) {
            if (is_string($this->input($field))) { $this->merge([$field => trim($this->input($field))]); }
        }
        foreach (['transaction_details', 'payout_details'] as $detailsField) {
            if (is_array($this->input($detailsField))) {
                $this->merge([$detailsField => array_map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value, $this->input($detailsField))]);
            }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $seen = [];
            foreach ((array) $this->input('sources', []) as $index => $source) {
                if (! is_array($source) || ! is_string($source['type'] ?? null) || ! is_scalar($source['id'] ?? null)) { continue; }
                $key = $source['type'].'|'.$source['id'];
                if (isset($seen[$key])) { $validator->errors()->add('sources.'.$index, 'Select each saved source once.'); }
                $seen[$key] = true;
            }
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field cannot be supplied.');
            }
        }];
    }
}
