<?php

namespace Tests\Feature;

use App\Models\ClinicalTemplateVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareFormPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_publication_retains_immutable_versions_and_refuses_unknown_fields(): void
    {
        $this->seed(DatabaseSeeder::class);
        $publisher=$this->createStaffWithRole('nurse');
        $path=tempnam(sys_get_temp_dir(),'care-form-');
        try {
            file_put_contents($path,json_encode(['type'=>'antenatal','code'=>'booking','title'=>'Approved test form',
                'fields'=>['observation'=>['type'=>'text','required'=>true]]],JSON_THROW_ON_ERROR));
            $this->artisan('care:publish-form',['definition'=>$path,'--publisher'=>$publisher->id])->assertFailed();
            $this->assertDatabaseCount('clinical_template_versions',0);
            $this->grantRolePermissions('nurse',['care.forms.publish']);
            $this->artisan('care:publish-form',['definition'=>$path,'--publisher'=>$publisher->id])->assertSuccessful();
            $this->artisan('care:publish-form',['definition'=>$path,'--publisher'=>$publisher->id])->assertSuccessful();
            $this->assertDatabaseCount('clinical_templates',1);
            $this->assertSame([1,2],ClinicalTemplateVersion::orderBy('version')->pluck('version')->all());
            file_put_contents($path,json_encode(['type'=>'antenatal','code'=>'booking','title'=>'Bad definition','fields'=>[
                'observation'=>['type'=>'invented-rule']]],JSON_THROW_ON_ERROR));
            $this->artisan('care:publish-form',['definition'=>$path,'--publisher'=>$publisher->id])->assertFailed();
            $this->assertDatabaseCount('clinical_template_versions',2);
            $this->assertDatabaseHas('audit_events', ['action' => 'care.form_published', 'actor_id' => $publisher->id, 'channel' => 'console', 'subject_type' => 'care_form']);
        } finally { unlink($path); }
    }
}
