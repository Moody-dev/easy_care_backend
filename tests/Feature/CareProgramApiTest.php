<?php

namespace Tests\Feature;

use App\Models\Birth;
use App\Models\CareProgram;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplate;
use App\Models\ClinicalTemplateVersion;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CareProgramApiTest extends TestCase
{
    use RefreshDatabase;
    private Encounter $visit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026,11,14)->setTime(12,0));
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('nurse', ['patients.view','encounters.view','encounters.view_any','care.view','care.manage',
            'care.write','care.sign','care.amend','care.void','care.review','care.link_birth']);
        $this->actingAs($this->createStaffWithRole('nurse'));
        $this->visit = Encounter::factory()->create();
    }

    #[DataProvider('types')]
    public function test_program_identity_and_receipt(string $type): void
    {
        $key=['Idempotency-Key'=>'care-opening-confirmation'];
        $data=['type'=>$type,'patient_version'=>1,'encounter_version'=>1,'identity_verified'=>true,'reason'=>'Private program opening'];
        $url='/api/v1/encounters/'.$this->visit->id.'/care-programs';
        $program=$this->postCommand($url,$data,$key)->assertCreated()->assertJsonPath('data.type',$type)
            ->assertJsonPath('data.patient_id',$this->visit->patient_id)->json('data');
        $this->postCommand($url,$data,$key)->assertOk()->assertHeader('Idempotency-Replayed','true')->assertJsonPath('data.id',$program['id']);
        $this->getJson('/api/v1/care-programs?type='.$type)->assertOk()->assertJsonCount(1,'data');
        $this->assertDatabaseCount('care_programs',1);
        $this->assertAuditEventRecorded('command.replayed', $this->app['auth']->id(), 'care_program', $program['id']);
        $this->assertAuditExcludes(['Private program opening']);
    }
    public static function types(): array { return array_map(fn (string $type): array=>[$type],CareProgram::TYPES); }

    public function test_form_sign_correction_and_retraction_preserve_original_values(): void
    {
        $program=$this->program();
        $path=$this->path($program);
        $draft=$this->postCommand($path,$this->entry($this->form('antenatal')->id))->assertCreated()->json('data');
        $this->postCommand($path.'/'.$draft['id'].'/sign',$this->signing(1))->assertOk()->assertJsonPath('data.status','signed');
        $data=$this->signing(2)+['reason'=>'Private correction reason','payload'=>['fields'=>['observation'=>'Corrected observation']]];
        $key=['Idempotency-Key'=>'care-correction-confirmation'];
        $replacement=$this->postCommand($path.'/'.$draft['id'].'/amend',$data,$key)->assertCreated()
            ->assertJsonPath('data.amends_id',$draft['id'])->assertJsonPath('data.care_program_id',$program->id)->json('data');
        $this->postCommand($path.'/'.$draft['id'].'/amend',$data,$key)->assertOk()->assertHeader('Idempotency-Replayed','true');
        $this->assertSame('Private observation',ClinicalRecord::findOrFail($draft['id'])->entry->fields['observation']);
        $this->getJson($path.'/'.$draft['id'].'/history')->assertOk()->assertJsonCount(2,'data');
        $this->postCommand($path.'/'.$replacement['id'].'/void',$this->signing(2)+['reason'=>'Retract erroneous observation'])
            ->assertOk()->assertJsonPath('data.status','voided');
        $this->assertDatabaseCount('care_record_links',3);
        $this->assertAuditExcludes(['Private observation','Corrected observation','Private correction reason']);
    }

    public function test_formless_draft_can_bind_an_approved_form_before_signing(): void
    {
        $p=$this->program(); $url=$this->path($p);
        $draft=$this->postCommand($url,['encounter_version'=>1,'program_version'=>1,'payload'=>['section'=>'note','content'=>'Private narrative']])->assertCreated()->json('data');
        $this->postCommand($url.'/'.$draft['id'].'/sign',$this->signing(1))->assertUnprocessable();
        $this->patchCommand($url.'/'.$draft['id'],['encounter_version'=>1,'program_version'=>1,'version'=>1,
            'payload'=>['clinical_template_version_id'=>$this->form('antenatal')->id,'fields'=>['observation'=>'Approved observation']]])->assertOk();
        $this->postCommand($url.'/'.$draft['id'].'/sign',$this->signing(2))->assertOk();
    }

    public function test_form_type_and_required_fields_are_enforced(): void
    {
        $url=$this->path($this->program());
        $this->postCommand($url,$this->entry($this->form('immunization')->id))->assertUnprocessable();
        $data=$this->entry($this->form('antenatal')->id); $data['payload']['fields']=[];
        $draft=$this->postCommand($url,$data)->assertCreated()->json('data');
        $this->postCommand($url.'/'.$draft['id'].'/sign',$this->signing(1))->assertUnprocessable()->assertJsonValidationErrors('payload.fields.observation');
    }

    public function test_twins_have_separate_identities_and_verified_links_cannot_reuse_the_mother_or_a_sibling(): void
    {
        $program=$this->program('delivery');
        $data=['encounter_id'=>$this->visit->id,'encounter_version'=>1,'program_version'=>1,'reason'=>'Verified baby identity'];
        $url='/api/v1/care-programs/'.$program->id.'/births';
        $first=$this->postCommand($url,$data+['birth_order'=>1])->assertCreated()->json('data');
        $this->assertSame($this->visit->id, $first['encounter_id']);
        $second=$this->postCommand($url,$data+['birth_order'=>2])->assertCreated()->json('data');
        $this->assertNotSame($first['id'],$second['id']);
        $this->postCommand($url,$data+['birth_order'=>1])->assertConflict();
        $link=$data+['child_patient_id'=>$this->visit->patient_id,'child_patient_version'=>1,'identity_verified'=>true];
        $this->postCommand($url.'/'.$first['id'].'/link',$link)->assertUnprocessable();
        $child=Patient::factory()->create(); $link['child_patient_id']=$child->id;
        $key=['Idempotency-Key'=>'birth-verified-confirmation'];
        $this->postCommand($url.'/'.$first['id'].'/link',$link,$key)->assertOk()->assertJsonPath('data.child_patient_id',$child->id)
            ->assertJsonPath('data.link.encounter_id', $this->visit->id);
        $this->postCommand($url.'/'.$first['id'].'/link',$link,$key)->assertOk()->assertHeader('Idempotency-Replayed','true');
        $this->postCommand($url.'/'.$second['id'].'/link',$link)->assertConflict();
        $child->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.child_patient_id',null)->assertJsonPath('data.0.link_exists',true);
        $this->postCommand($url.'/'.$first['id'].'/link',$link,$key)->assertNotFound();
    }

    public function test_baby_corrections_keep_the_original_subject_and_reject_another_delivery(): void
    {
        $p=$this->program('delivery'); $birth=Birth::factory()->create(['delivery_id'=>$p->id]); $other=Birth::factory()->create();
        $data=$this->entry($this->form('birth')->id)+['birth_id'=>$other->id]; $url=$this->path($p);
        $this->postCommand($url,$data)->assertNotFound();
        $data['birth_id']=$birth->id;
        $draft=$this->postCommand($url,$data)->assertCreated()->json('data');
        $this->postCommand($url.'/'.$draft['id'].'/sign',$this->signing(1))->assertOk();
        $this->postCommand($url.'/'.$draft['id'].'/amend',$this->signing(2)+['reason'=>'Correct baby observation',
            'payload'=>['fields'=>['observation'=>'Corrected baby observation']]])->assertCreated()->assertJsonPath('data.birth_id',$birth->id);
    }

    public function test_generic_clinical_access_does_not_bypass_care_permissions(): void
    {
        $p=$this->program(); $draft=$this->postCommand($this->path($p),$this->entry($this->form('antenatal')->id))->assertCreated()->json('data');
        $this->grantRolePermissions('nurse',['clinical.view','clinical.write','clinical.sign']);
        $url='/api/v1/encounters/'.$this->visit->id.'/clinical/'.$draft['id'];
        $this->getJson($url)->assertNotFound();
        $this->postCommand($url.'/sign',['encounter_version'=>1,'version'=>1,'current_password'=>self::STAFF_TEST_PASSWORD])->assertNotFound();
        Role::where('code','nurse')->sole()->permissions()->detach(Permission::where('code','care.view')->sole()->id);
        $this->getJson('/api/v1/care-programs/'.$p->id)->assertForbidden();
    }

    public function test_completion_blocks_pending_drafts_and_keeps_final_history_read_only(): void
    {
        $p=$this->program(); $url=$this->path($p); $draft=$this->postCommand($url,$this->entry($this->form('antenatal')->id))->assertCreated()->json('data');
        $data=['encounter_id'=>$this->visit->id,'encounter_version'=>1,'program_version'=>1,'reason'=>'Completed program review'];
        $this->postCommand('/api/v1/care-programs/'.$p->id.'/complete',$data)->assertConflict();
        $this->postCommand($url.'/'.$draft['id'].'/void',$this->signing(1)+['reason'=>'Withdraw unsigned draft'])->assertOk();
        $this->postCommand('/api/v1/care-programs/'.$p->id.'/complete',$data)->assertOk()->assertJsonPath('data.status','completed')
            ->assertJsonPath('data.completed_encounter_id', $this->visit->id);
        $this->postCommand($url,$this->entry($this->form('antenatal')->id))->assertConflict();
        $this->getJson('/api/v1/care-programs/'.$p->id.'/records')->assertOk()->assertJsonCount(1,'data');
    }

    public function test_cross_patient_visits_restrictions_and_other_authors_drafts_are_hidden(): void
    {
        $p=$this->program(); $url=$this->path($p); $draft=$this->postCommand($url,$this->entry($this->form('antenatal')->id))->assertCreated()->json('data');
        $other=Encounter::factory()->create();
        $this->postCommand('/api/v1/care-programs/'.$p->id.'/encounters/'.$other->id.'/records',$this->entry($this->form('antenatal')->id))->assertNotFound();
        $this->flushSession(); $this->actingAs($this->createStaffWithRole('nurse'));
        $this->getJson($url.'/'.$draft['id'])->assertNotFound();
        $this->visit->forceFill(['status'=>'cancelled','closed_at'=>now('UTC'),'version'=>2])->save();
        $data=$this->entry($this->form('antenatal')->id); $data['encounter_version']=2;
        $this->postCommand($url,$data)->assertConflict();
        $this->visit->patient->forceFill(['access_status'=>'restricted','version'=>2])->save();
        $this->getJson('/api/v1/care-programs/'.$p->id)->assertNotFound();
        $this->getJson('/api/v1/care-programs')->assertOk()->assertJsonCount(0,'data');
    }

    #[DataProvider('failureTables')]
    public function test_failed_record_creation_rolls_back_content_links_history_and_receipts(string $table): void
    {
        $p=$this->program(); $form=$this->form('antenatal'); $hit=$this->failNextWriteTo($table,'insert');
        $this->assertTablesUnchanged(['clinical_records','clinical_entries','care_record_links','clinical_revisions','idempotency_requests'],
            fn ()=>$this->postCommand($this->path($p),$this->entry($form->id))->assertServerError());
        $this->assertTrue($hit());
    }
    public static function failureTables(): array { return [['care_record_links'],['clinical_entries'],['clinical_revisions']]; }

    public function test_malformed_management_identity_and_stale_parent_versions_fail_without_writes(): void
    {
        $program = $this->program();
        $data = ['encounter_id' => [$this->visit->id], 'encounter_version' => 1, 'program_version' => 1, 'reason' => 'Complete reviewed program'];
        $this->postCommand('/api/v1/care-programs/'.$program->id.'/complete', $data)->assertUnprocessable()->assertJsonValidationErrors('encounter_id');
        $data['encounter_id'] = $this->visit->id;
        $data['program_version'] = 2;
        $this->postCommand('/api/v1/care-programs/'.$program->id.'/complete', $data)->assertConflict()->assertJsonPath('code', 'version_conflict');
        $this->assertSame('active', $program->fresh()->status);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }
    private function program(string $type='antenatal'): CareProgram { return CareProgram::factory()->create(['encounter_id'=>$this->visit->id,'type'=>$type]); }
    private function path(CareProgram $p): string { return '/api/v1/care-programs/'.$p->id.'/encounters/'.$this->visit->id.'/records'; }
    private function form(string $type): ClinicalTemplateVersion
    {
        $t=ClinicalTemplate::factory()->create(['code'=>'care.'.$type.'.form'.fake()->unique()->numerify('######')]);
        return ClinicalTemplateVersion::factory()->create(['clinical_template_id'=>$t->id,'fields'=>['observation'=>['type'=>'text','required'=>true,'label'=>'Observation']]]);
    }
    private function entry(int $template): array { return ['encounter_version'=>1,'program_version'=>1,'payload'=>['section'=>'note','clinical_template_version_id'=>$template,'fields'=>['observation'=>'Private observation']]]; }
    private function signing(int $version): array { return ['encounter_version'=>1,'program_version'=>1,'version'=>$version,'current_password'=>self::STAFF_TEST_PASSWORD]; }
}
