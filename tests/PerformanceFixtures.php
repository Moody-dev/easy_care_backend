<?php

namespace Tests;

use App\Models\Appointment;
use App\Models\BillingAccount;
use App\Models\BillingReceipt;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PerformanceFixtures
{
    public const PASSWORD='Synthetic-performance-password-1!';
    public const PATIENTS=10000;
    public const STAFF=10;

    public function build(array $actors,array $doctors): array
    {
        $category=PatientCategory::query()->firstOrFail()->id;
        $time=now('UTC')->format('Y-m-d H:i:s.u');
        $opened=now('UTC')->subHour()->format('Y-m-d H:i:s.u');
        Hash::driver()->setRounds(12);
        $password=Hash::make(self::PASSWORD,['rounds'=>12]);
        foreach ($actors as $actor) { $actor->forceFill(['password'=>$password])->save(); }
        DB::transaction(function () use ($actors,$doctors,$category,$time,$opened): void {
            for ($start=1;$start<=self::PATIENTS;$start+=200) {
                $rows=[];
                for ($id=$start;$id<min($start+200,self::PATIENTS+1);$id++) {
                    $rows[]=Patient::factory()->make(['id'=>$id,'mrn'=>'PERF-'.str_pad((string)$id,6,'0',STR_PAD_LEFT),
                        'first_name'=>'Synthetic','last_name'=>'Cohort'.str_pad((string)($id%100),3,'0',STR_PAD_LEFT),
                        'category_id'=>$category,'created_by'=>$actors[0]->id,'access_status'=>$id>9900?'restricted':'active',
                        'created_at'=>$time,'updated_at'=>$time])->getAttributes();
                }
                DB::table('patients')->insert($rows);
            }
            for ($start=1;$start<=2000;$start+=200) {
                $visits=$bookings=$assignments=[];
                for ($id=$start;$id<$start+200;$id++) {
                    $visits[]=Encounter::factory()->make(['id'=>$id,'patient_id'=>$id,'category_id'=>$category,
                        'opened_by'=>$actors[0]->id,'opened_at'=>$opened,'created_at'=>$time,'updated_at'=>$time])->getAttributes();
                    $bookings[]=Appointment::factory()->make(['id'=>$id,'patient_id'=>$id,'doctor_id'=>null,'booked_by'=>$actors[0]->id,
                        'starts_at'=>now('UTC')->addDay()->startOfHour(),'ends_at'=>now('UTC')->addDay()->startOfHour()->addMinutes(30),
                        'created_at'=>$time,'updated_at'=>$time])->getAttributes();
                    if ($id%2===0) {
                        $assignments[]=DoctorAssignment::factory()->make(['encounter_id'=>$id,'doctor_id'=>$doctors[$id%count($doctors)]->id,
                            'assigned_by'=>$actors[0]->id,'assigned_at'=>$time,'created_at'=>$time,'updated_at'=>$time])->getAttributes();
                    }
                }
                DB::table('encounters')->insert($visits); DB::table('appointments')->insert($bookings);
                if ($assignments!==[]) { DB::table('doctor_assignments')->insert($assignments); }
            }
            for ($start=1;$start<=1000;$start+=200) {
                $rows=$receipts=$events=[];
                for ($id=$start;$id<$start+200;$id++) {
                    $rows[]=BillingAccount::factory()->make(['id'=>$id,'patient_id'=>$id,'actor_id'=>$actors[0]->id,'recorded_at'=>$time])->getAttributes();
                    $receipts[]=BillingReceipt::factory()->make(['id'=>$id,'account_id'=>$id,'actor_id'=>$actors[0]->id,'amount_kobo'=>1000,
                        'recorded_at'=>$time,'paid_at'=>$time])->getAttributes();
                    $events[]=['account_id'=>$id,'version'=>1,'action'=>'receipt','record_id'=>$id,'actor_id'=>$actors[0]->id,
                        'recorded_at'=>$time,'reason'=>'Synthetic performance opening receipt'];
                }
                DB::table('billing_accounts')->insert($rows);
                DB::table('billing_receipts')->insert($receipts); DB::table('billing_events')->insert($events);
            }
        });
        return ['patients'=>self::PATIENTS,'visible_patients'=>9900,'appointments'=>2000,'encounters'=>2000,
            'current_assignments'=>1000,'billing_accounts'=>1000,'recorded_receipts'=>1000,'staff_requests'=>self::STAFF,'password_rounds'=>12];
    }
}
