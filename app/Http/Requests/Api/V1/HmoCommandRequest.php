<?php

namespace App\Http\Requests\Api\V1;

use App\Services\HmoWorkflows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class HmoCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $id=['required','integer','min:1','max:999999999999999999'];
        $version=['required','integer','min:0','max:4294967293'];
        $text=['required','string','min:1','max:128']; $reason=['required','string','min:5','max:2000'];
        $date=['required','string','date','after_or_equal:1000-01-01T00:00:00Z','regex:/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$/D'];
        $day=['required','date_format:Y-m-d','after_or_equal:1000-01-01'];
        $money=['required','string','max:15']; $action=HmoWorkflows::action($this);
        $rules=['current_password'=>['required','string','max:1024'],'reason'=>$reason];
        if (!HmoWorkflows::isCatalog($this)) { $rules['account_version']=$version; }
        if (in_array($action,['provider-create','provider-update','plan-create','plan-update'],true)) {
            $rules+=['is_active'=>['required','boolean'],'preauth_required'=>['required','boolean'],
                'details'=>['sometimes','array:phone,email,address,contact_person,billing_frequency,treatment_guide'],
                'details.phone'=>['sometimes','string','max:32'],'details.email'=>['sometimes','email','max:191'],
                'details.address'=>['sometimes','string','max:2000'],'details.contact_person'=>['sometimes','string','max:191'],
                'details.billing_frequency'=>['sometimes','string','max:128'],'details.treatment_guide'=>['sometimes','string','max:2000']];
            if (str_ends_with($action,'create')) {
                $rules+=['code'=>['required','string','regex:/^[a-z][a-z0-9._-]{0,63}$/D'],'name'=>['required','string','min:1','max:191']];
                if ($action==='plan-create') { $rules['provider_id']=$id; }
            } else { $rules['version']=$version; }
            return $rules;
        }
        if ($action==='tariff-import') { return $rules+['plan_id'=>$id,'expected_version'=>$version,'filename'=>['required','string','max:128','regex:/^[A-Za-z0-9_. -]+\\.csv$/D'],
            'csv_content'=>['required','string','max:1048576'],'approval_reference'=>$reason,'effective_at'=>['sometimes',...$date]]; }
        if ($action==='remittance-create') {
            return $rules+['provider_id'=>$id,'amount'=>$money,'reference'=>$text,'method'=>['required',Rule::in(['cash','bank_transfer','pos','other'])],
                'payer_name'=>['required','string','min:1','max:191'],'received_at'=>$date,'externally_confirmed'=>['required','accepted'],
                'transaction_details'=>['required','array:provider,terminal_id,authorization_code,receipt_number,bank_name,method_name,notes'],
                'transaction_details.*'=>['string','max:2000']];
        }
        if (str_ends_with($action,'review')) {
            $rules+=['version'=>$version,'outcome'=>['required',Rule::in(['accepted','rejected'])],'externally_verified'=>['required','accepted']];
            if ($action==='allocation-review') { $rules['remittance_version']=$version; }
            return $rules;
        }
        if ($action==='coverage-create') { return $rules+['plan_id'=>$id,'member_number'=>$text,'valid_from'=>$day,
            'valid_until'=>[...$day,'after_or_equal:valid_from'],'verification_reference'=>$reason,'verified'=>['required','accepted'],
            'group_number'=>['sometimes','nullable','string','max:128'],'employer_name'=>['sometimes','nullable','string','max:191']]; }
        if (str_ends_with($action,'revoke')) { return $rules; }
        if ($action==='preauth-create') { return $rules+['coverage_id'=>$id,'encounter_id'=>$id,'encounter_version'=>$version,'subject_type'=>['required',Rule::in(['service','drug'])],
            'subject_id'=>$id,'status'=>['required',Rule::in(['approved','denied'])],'limit'=>$money,'reference'=>$text,'approved_at'=>$date,
            'valid_from'=>$date,'valid_until'=>[...$date,'after_or_equal:valid_from'],'externally_verified'=>['required','accepted']]; }
        if ($action==='invoice') { return $rules+['coverage_id'=>$id,'encounter_id'=>$id,'encounter_version'=>$version,'split_confirmed'=>['required','accepted'],
            'sources'=>['required','array','list','min:1','max:50'],'sources.*'=>['required','array:type,id,tariff_id,patient_amount,insurer_amount,benefit_reference,preauth_id'],
            'sources.*.type'=>['required',Rule::in(['performed','investigation','dispense'])],'sources.*.id'=>$id,'sources.*.tariff_id'=>$id,
            'sources.*.patient_amount'=>$money,'sources.*.insurer_amount'=>$money,'sources.*.benefit_reference'=>$reason,'sources.*.preauth_id'=>['sometimes',...$id]]; }
        if ($action==='claim-submit'||$action==='claim-resubmit') {
            return $rules+($action==='claim-submit'?['invoice_id'=>$id]:[])+['reference'=>$text,'submitted_at'=>$date,'externally_submitted'=>['required','accepted']];
        }
        if ($action==='decision-record') { return $rules+['claim_id'=>$id,'submission_id'=>$id,'reference'=>$text,'occurred_at'=>$date,
            'lines'=>['required','array','list','min:1','max:50'],'lines.*'=>['required','array:item_id,approved_amount,reason'],
            'lines.*.item_id'=>[...$id,'distinct:strict'],'lines.*.approved_amount'=>$money,'lines.*.reason'=>$reason]; }
        return $rules+['claim_id'=>$id,'remittance_id'=>$id,'remittance_version'=>$version,'reference'=>$text,
            'lines'=>['required','array','list','min:1','max:50'],'lines.*'=>['required','array:item_id,amount'],
            'lines.*.item_id'=>[...$id,'distinct:strict'],'lines.*.amount'=>$money];
    }

    protected function prepareForValidation(): void
    {
        foreach (['reason','name','code','reference','payer_name','member_number','verification_reference','approval_reference'] as $field) {
            if (is_string($this->input($field))) { $this->merge([$field=>trim($this->input($field))]); }
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()),array_keys($this->rules())) as $field) { $validator->errors()->add($field,'This field cannot be supplied.'); }
            $seen=[];
            foreach ((array)$this->input('sources',[]) as $index=>$source) {
                if (!is_array($source)||!is_string($source['type']??null)||!is_scalar($source['id']??null)) { continue; }
                $key=$source['type'].'|'.$source['id'];
                if (isset($seen[$key])) { $validator->errors()->add('sources.'.$index,'Select each original source once.'); }
                $seen[$key]=true;
            }
        }];
    }
}
