<?php

namespace Tests;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\PrescriptionItem;
use App\Models\User;
use Tests\StockOperationsTestCase;

abstract class MedicationFulfillmentTestCase extends StockOperationsTestCase
{
    protected User $doctor;

    protected User $nurse;

    protected Encounter $visit;

    protected DoctorAssignment $assignment;

    protected PrescriptionItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->stockFixtures();
        $permissions = ['patients.view', 'encounters.view', 'encounters.view_any', 'prescriptions.view', 'medications.view'];
        foreach (['doctor', 'nurse', 'pharmacy'] as $role) {
            $this->grantRolePermissions($role, $permissions);
        }
        $this->grantRolePermissions('doctor', ['prescriptions.write', 'prescriptions.sign', 'prescriptions.amend', 'prescriptions.void', 'medications.authorize', 'stock.catalog.view']);
        $this->grantRolePermissions('nurse', ['medications.administer', 'medications.correct']);
        $this->grantRolePermissions('pharmacy', ['medications.dispense', 'medications.returns.request', 'medications.returns.approve', 'medications.review']);
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->nurse = $this->createStaffWithRole('nurse');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $this->assignment = DoctorAssignment::factory()->create(['encounter_id' => $this->visit->id,
            'doctor_id' => $this->doctor->id, 'assigned_at' => now('UTC')->subMinutes(50)]);
        $this->stockLogin($this->doctor);
        $path = '/api/v1/encounters/'.$this->visit->id.'/prescriptions';
        $draft = $this->postCommand($path, ['encounter_version' => 1, 'payload' => ['items' => [[
            'medication_name' => 'Synthetic unlinked medication', 'dose' => 'Prescriber instructions', 'route' => 'Staff entered', 'frequency' => 'Staff entered']]]])->assertCreated()->json('data');
        $this->postCommand($path.'/'.$draft['id'].'/sign', ['encounter_version' => 1, 'version' => 1,
            'current_password' => self::STAFF_TEST_PASSWORD])->assertOk();
        $this->item = PrescriptionItem::where('prescription_id', $draft['id'])->sole();
        $this->postReceipt();
    }

    protected function authorizeLine(array $overrides = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        $this->stockLogin($this->doctor);

        return $this->postCommand('/api/v1/medications/authorizations', array_replace([
            'encounter_version' => 1, 'prescription_item_id' => $this->item->id, 'prescription_version' => 2,
            'assignment_id' => $this->assignment->id, 'assignment_version' => 1, 'drug_id' => $this->batch->drug_id,
            'drug_version' => 1, 'maximum_quantity' => '6', 'reason' => 'Explicit synthetic catalog authorization',
            'current_password' => self::STAFF_TEST_PASSWORD], $overrides), $headers);
    }

    protected function command(array $authorization, string $action, array $data = [], ?User $actor = null, array $headers = []): \Illuminate\Testing\TestResponse
    {
        $this->stockLogin($actor ?? $this->preparer);

        return $this->postCommand('/api/v1/medications/'.$authorization['id'].'/'.$action, array_replace([
            'encounter_version' => 1, 'version' => $authorization['version'], 'reason' => 'Private synthetic medication action',
            'current_password' => self::STAFF_TEST_PASSWORD], $data), $headers);
    }

    protected function issue(array $authorization, string $quantity = '4', int $balanceVersion = 1, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->command($authorization, 'dispense', ['quantity' => $quantity, 'batch_id' => $this->batch->id,
            'store_id' => $this->mainStore->id, 'store_version' => 1, 'drug_version' => 1, 'balance_version' => $balanceVersion], headers: $headers);
    }

}
