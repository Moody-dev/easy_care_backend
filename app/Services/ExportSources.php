<?php

namespace App\Services;

use App\Http\Resources\ClinicalResource;
use App\Http\Resources\InvestigationResultResource;
use App\Models\BillingAccount;
use App\Models\ClinicalRecord;
use App\Models\Encounter;
use App\Models\InvestigationResult;
use App\Models\Patient;
use App\Models\User;
use App\Support\BillingMoney;
use App\Support\BillingRecordData;
use Illuminate\Support\Facades\DB;

class ExportSources
{
    public function __construct(private StockAuthority $authority,private ExportReports $reports,private BillingBalances $balances) {}

    public function snapshot(User $actor,array $input): array
    {
        if ($input['kind']==='report') { return $this->reports->snapshot($actor,$input); }
        $kind=$input['kind']; $id=(int)$input['source_id'];
        foreach (['patients.view', ...match ($kind) {
            'invoice','receipt'=>['billing.view'],
            'prescription'=>['encounters.view','prescriptions.view'],
            'clinical'=>['encounters.view','clinical.view'],
            'result'=>['encounters.view','investigations.view','investigations.results.view'],
        }] as $permission) { $this->authority->permission($actor,$permission); }

        if (in_array($kind,['invoice','receipt'],true)) { return $this->financial($actor,$kind,$id); }
        if ($kind==='result') {
            $record=InvestigationResult::query()->visibleTo($actor)->where('status','released')
                ->whereDoesntHave('replacements',fn ($query)=>$query->where('status','released'))->lockForUpdate()->findOrFail($id);
            $record->load(InvestigationResults::LOAD);
            $patient=$record->item->order->patient;
            $body=(new InvestigationResultResource($record))->resolve();
            unset($body['attachments']);
            $body['attachments_included']=false;
            $body['test_name']=$record->item->test->name;
            $body['author_name']=$record->author?->name;
            $body['verifier_name']=$record->verifier?->name;
            $body['releaser_name']=$record->releaser?->name;
        } else {
            $query=$kind==='prescription'?ClinicalRecord::query()->prescriptionsVisibleTo($actor):ClinicalRecord::query()->visibleTo($actor);
            $record=$query->current()->where('status','signed')->lockForUpdate()->findOrFail($id);
            $record->load([...ClinicalRecords::LOAD,'prescription.items','patient','encounter']);
            $patient=$record->patient;
            $body=(new ClinicalResource($record))->resolve();
            unset($body['body_mass_index']);
        }
        $visit=Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($record->encounter_id);
        $data=['document_kind'=>$kind,'patient'=>$this->identity($patient),'saved_record'=>$body];
        return $this->result($data,['record_version'=>$record->version,'patient_version'=>$patient->version,'encounter_version'=>$visit->version]);
    }

    private function financial(User $actor,string $kind,int $id): array
    {
        $table=$kind==='invoice'?'billing_invoices':'billing_receipts';
        $row=DB::table($table)->whereIn('account_id',BillingAccount::query()->whereIn('patient_id',Patient::query()->visibleTo($actor)->select('patients.id'))->select('id'))
            ->lockForUpdate()->find($id);
        abort_if($row===null,404);
        $account=BillingAccount::query()->lockForUpdate()->findOrFail($row->account_id);
        $patient=Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($account->patient_id);
        $state=$this->balances->snapshot($account);
        $body=BillingRecordData::format($row);
        $body['balance']=array_map(BillingMoney::format(...),$state[$kind==='invoice'?'invoiceData':'receiptData'][$id]);
        if ($kind==='invoice') {
            abort_unless(DB::table('billing_invoice_posts')->where('invoice_id',$id)->exists(),409);
            $body['items']=$state['items']->where('invoice_id',$id)->map(BillingRecordData::format(...))->values()->all();
            if ($actor->hasPermission('hmo.view')) {
                $body['payer_splits']=DB::table('hmo_invoice_shares')->whereIn('item_id',$state['items']->where('invoice_id',$id)->pluck('id'))
                    ->orderBy('id')->get()->map(BillingRecordData::format(...))->all();
            }
        }
        $body['recorded_by_name']=User::query()->whereKey($row->actor_id)->value('name');
        return $this->result(['document_kind'=>$kind,'patient'=>$this->identity($patient),'currency'=>'NGN',
            'payments_processed_by_platform'=>false,'saved_record'=>$body],
            ['patient_version'=>$patient->version,'ledger_version'=>DB::table('billing_events')->where('account_id',$account->id)->max('version')]);
    }

    private function identity(Patient $patient): array
    {
        return $patient->only(['id','mrn','first_name','middle_name','last_name','dob_estimated'])
            +['date_of_birth'=>$patient->date_of_birth?->toDateString()];
    }

    private function result(array $data,array $versions): array
    {
        return ['data'=>$data,'fingerprint'=>hash('sha256',json_encode([$data,$versions],JSON_THROW_ON_ERROR))];
    }
}
