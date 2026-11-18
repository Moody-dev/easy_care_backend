<?php

namespace App\Http\Requests\Api\V1;

use App\Services\ReminderWorkflows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReminderCommandRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $action=ReminderWorkflows::action($this);
        $date=['required','string','date','after_or_equal:1000-01-01T00:00:00Z','before_or_equal:9999-12-31T23:59:59Z',
            'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'];
        $version=['required','integer','min:1','max:4294967293'];
        if (ReminderWorkflows::isNotification($this)) {
            return ['version'=>$version]+($action==='snoozed'?['snooze_until'=>$date]:[]);
        }
        if ($action==='cancelled') { return ['version'=>$version]; }
        $rules=['title'=>['required','string','min:1','max:191'],'body'=>['sometimes','nullable','string','max:4000'],
            'starts_at'=>$date,'timezone'=>['required',Rule::in(['Africa/Lagos'])],
            'frequency'=>['required',Rule::in(['once','daily','weekly','monthly'])],'interval'=>['required','integer','min:1','max:365'],
            'occurrence_count'=>['required','integer','min:1','max:366'],'ends_at'=>['sometimes','nullable',...array_values(array_diff($date,['required'])),'after_or_equal:starts_at'],
            'recipient_ids'=>['required','array','list','min:1','max:50'],'recipient_ids.*'=>['required','integer','min:1','max:999999999999999999','distinct:strict']];
        if ($action==='updated') { $rules['version']=$version; }
        else {
            $rules+=['patient_id'=>['sometimes','nullable','integer','min:1','max:999999999999999999'],
                'encounter_id'=>['sometimes','nullable','integer','min:1','max:999999999999999999']];
        }
        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed=array_filter(array_keys($this->rules()),fn (string $key): bool => !str_contains($key,'.'));
            foreach (array_diff(array_keys($this->all()),$allowed) as $field) { $validator->errors()->add($field,'This field cannot be supplied.'); }
            $ids=$this->input('recipient_ids');
            if (is_array($ids) && count(array_filter($ids,'is_scalar'))===count($ids)
                && count(array_unique(array_map('intval',$ids)))!==count($ids)) {
                $validator->errors()->add('recipient_ids','Select each saved staff member once.');
            }
            if ($this->input('frequency')==='once' && ((int)$this->input('interval')!==1 || (int)$this->input('occurrence_count')!==1)) {
                $validator->errors()->add('frequency','One-time reminders require interval and occurrence count of one.');
            }
            if ($this->filled('encounter_id') && !$this->filled('patient_id') && ReminderWorkflows::action($this)==='created') {
                $validator->errors()->add('patient_id','Select the saved patient for this visit.');
            }
        }];
    }
}
