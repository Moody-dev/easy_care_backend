<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientIdentifier;
use App\Models\User;
use App\Services\AuditTrail;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PatientNumberReservationTest extends TestCase
{
    use RefreshDatabase;

    private User $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->grantRolePermissions('frontdesk', ['patients.view', 'patients.create', 'patients.update', 'patients.cards.manage']);
        $this->registrar = $this->createStaffWithRole('frontdesk');
        $this->actingAs($this->registrar);
    }

    public function test_categories_have_independent_sequences_and_reservations_do_not_create_patients(): void
    {
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-01')->assertJsonPath('data.read_only', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-02');
        $this->reserve('walk-in')->assertCreated()->assertJsonPath('data.mrn', 'WLK-01');
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_number_reservations', 3);
    }

    public function test_same_key_returns_the_same_number_and_token_and_does_not_increment(): void
    {
        $key = (string) Str::uuid();
        $first = $this->reserve(key: $key)->assertCreated()->json('data');
        $this->reserve(key: $key)->assertOk()->assertExactJson(['data' => $first])->assertHeader('Idempotency-Replayed', 'true');
        $this->reserve('walk-in', $key)->assertConflict()->assertJsonPath('code', 'idempotency_conflict');
        $this->assertDatabaseCount('patient_number_reservations', 1);
        $row = DB::table('patient_number_reservations')->sole();
        $this->assertSame(hash('sha256', $first['number_reservation_token']), $row->token_hash);
        $this->assertStringNotContainsString($first['number_reservation_token'], $row->encrypted_token);
        $this->assertSame(1, (int) DB::table('patient_number_sequences')->where('prefix', 'PVT')->value('last_value'));
    }

    public function test_registration_consumes_the_reserved_number_and_exact_retries_do_not_register_twice(): void
    {
        $reservation = $this->reserve()->assertCreated()->json('data');
        $data = $this->registration($reservation);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $id = $this->postCommand('/api/v1/patients', $data, $headers)->assertCreated()->assertJsonPath('data.mrn', 'PVT-01')->json('data.id');
        $this->postCommand('/api/v1/patients', $data, $headers)->assertOk()->assertJsonPath('data.id', $id)
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand('/api/v1/patients', $data)->assertConflict()->assertJsonPath('code', 'number_reservation_consumed');
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patient_number_reservations', ['id' => $reservation['id'], 'patient_id' => $id]);
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-02');
    }

    public function test_number_echo_is_optional_but_cannot_override_the_reserved_number(): void
    {
        $reservation = $this->reserve()->json('data');
        $data = $this->registration($reservation);
        $this->postCommand('/api/v1/patients', $data + ['mrn' => 'PVT-999'])->assertUnprocessable()->assertJsonValidationErrors('mrn');
        $this->assertDatabaseCount('patients', 0);
        $this->postCommand('/api/v1/patients', $data + ['mrn' => $reservation['mrn']])->assertCreated()->assertJsonPath('data.mrn', 'PVT-01');
    }

    public function test_other_staff_and_categories_cannot_claim_a_reservation(): void
    {
        $reservation = $this->reserve()->json('data');
        $data = $this->registration($reservation);
        $this->postCommand('/api/v1/patients', array_replace($data, ['category_id' => $this->category('walk-in')]))
            ->assertUnprocessable()->assertJsonValidationErrors('number_reservation_token');
        $other = $this->createStaffWithRole('frontdesk');
        session()->flush();
        $this->actingAs($other)->postCommand('/api/v1/patients', $data)->assertUnprocessable()->assertJsonValidationErrors('number_reservation_token');
        $this->assertDatabaseCount('patients', 0);
        session()->flush();
        $this->actingAs($this->registrar)->postCommand('/api/v1/patients', $data)->assertCreated();
    }

    public function test_automatic_categories_require_a_reservation_and_other_categories_keep_manual_numbers(): void
    {
        foreach (['private', 'walk-in'] as $code) {
            $this->postCommand('/api/v1/patients', ['first_name' => 'Example', 'last_name' => 'Patient',
                'mrn' => 'MANUAL-01', 'category_id' => $this->category($code)])->assertUnprocessable()
                ->assertJsonValidationErrors('number_reservation_token');
        }
        $this->reserve('company')->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postCommand('/api/v1/patients', ['first_name' => 'Example', 'last_name' => 'Patient',
            'mrn' => 'COMPANY-01', 'category_id' => $this->category('company')])->assertCreated()->assertJsonPath('data.mrn', 'COMPANY-01');
        $this->postCommand('/api/v1/patients', ['first_name' => 'Another', 'last_name' => 'Patient',
            'category_id' => $this->category('company')])->assertUnprocessable()->assertJsonValidationErrors('mrn');
    }

    public function test_existing_and_retired_identifiers_set_the_next_number_without_exposing_records(): void
    {
        $patient = Patient::factory()->create(['mrn' => 'PVT-008', 'created_by' => $this->registrar->id, 'access_status' => 'restricted']);
        PatientIdentifier::factory()->for($patient)->create(['identifier' => 'PVT-56']);
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-57')->assertJsonMissingPath('data.patient_id');
        $this->assertSame('PVT-008', $patient->fresh()->mrn);
    }

    public function test_abandoned_numbers_are_retained_and_padding_expands_after_ninety_nine(): void
    {
        DB::table('patient_number_sequences')->insertOrIgnore(['prefix' => 'PVT', 'last_value' => 0]);
        DB::table('patient_number_sequences')->where('prefix', 'PVT')->update(['last_value' => 98]);
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-99');
        $this->reserve()->assertCreated()->assertJsonPath('data.mrn', 'PVT-100');
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_legacy_registration_and_card_changes_cannot_take_an_unconsumed_reservation(): void
    {
        $reservation = $this->reserve()->json('data');
        $this->postCommand('/api/v1/patients', ['first_name' => 'Example', 'last_name' => 'Patient',
            'mrn' => $reservation['mrn'], 'category_id' => $this->category('company')])->assertUnprocessable()->assertJsonValidationErrors('mrn');
        $patient = Patient::factory()->create(['created_by' => $this->registrar->id]);
        $this->postCommand('/api/v1/patients/'.$patient->id.'/card', ['version' => 1,
            'category_id' => $this->category('company'), 'mrn' => $reservation['mrn'],
            'current_password' => self::STAFF_TEST_PASSWORD, 'reason' => 'Synthetic card correction'])
            ->assertUnprocessable()->assertJsonValidationErrors('mrn');
    }

    public function test_number_options_and_access_permissions_are_explicit(): void
    {
        $options = collect($this->getJson('/api/v1/patients/options')->assertOk()->json('data.categories'))->keyBy('code');
        $this->assertSame('reservation_required', $options['private']['numbering_mode']);
        $this->assertSame('PVT', $options['private']['number_prefix']);
        $this->assertSame('staff_entered', $options['company']['numbering_mode']);
        $this->reserve(key: 'short')->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postCommand('/api/v1/patients/number-reservations', ['category_id' => $this->category('private'), 'mrn' => 'PVT-99'])
            ->assertUnprocessable()->assertJsonValidationErrors('mrn');
        $unprivileged = User::factory()->create();
        session()->flush();
        $this->actingAs($unprivileged);
        $this->reserve()->assertForbidden();
        $this->assertDatabaseCount('patient_number_reservations', 0);
    }

    public function test_a_failed_reservation_rolls_back_the_counter_and_preserves_the_retry_key(): void
    {
        $this->mock(AuditTrail::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        });
        $this->reserve()->assertStatus(500);
        $this->assertDatabaseCount('patient_number_reservations', 0);
        $this->assertSame(0, (int) DB::table('patient_number_sequences')->where('prefix', 'PVT')->value('last_value'));
    }

    public function test_failed_patient_save_leaves_the_number_reserved_for_the_same_retry(): void
    {
        $reservation = $this->reserve()->json('data');
        $data = $this->registration($reservation);
        $data['first_name'] = '';
        $this->postCommand('/api/v1/patients', $data)->assertUnprocessable();
        $this->assertNull(DB::table('patient_number_reservations')->where('id', $reservation['id'])->value('patient_id'));
        $this->postCommand('/api/v1/patients', $this->registration($reservation))->assertCreated();
    }

    public function test_failure_after_consuming_a_number_rolls_back_the_patient_and_allows_the_original_retry(): void
    {
        $reservation = $this->reserve()->json('data');
        $data = $this->registration($reservation);
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $this->mock(AuditTrail::class, function ($mock): void {
            $mock->shouldReceive('record')->andThrow(new RuntimeException('Synthetic patient audit failure'));
        });
        $this->postCommand('/api/v1/patients', $data, $headers)->assertStatus(500);
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_identifiers', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
        $this->assertNull(DB::table('patient_number_reservations')->where('id', $reservation['id'])->value('patient_id'));
        $this->app->forgetInstance(AuditTrail::class);
        $this->postCommand('/api/v1/patients', $data, $headers)->assertCreated()->assertJsonPath('data.mrn', $reservation['mrn']);
    }

    public function test_revoked_permissions_prevent_reservation_replay_and_number_consumption(): void
    {
        $key = (string) Str::uuid();
        $reservation = $this->reserve(key: $key)->json('data');
        \App\Models\Role::where('code', 'frontdesk')->sole()->permissions()->detach(
            \App\Models\Permission::where('code', 'patients.create')->sole()->id);
        $this->reserve(key: $key)->assertForbidden();
        $this->postCommand('/api/v1/patients', $this->registration($reservation))->assertForbidden();
        $this->assertDatabaseCount('patients', 0);
        $this->assertDatabaseCount('patient_number_reservations', 1);
    }

    public function test_database_guards_preserve_number_history_and_counter_monotonicity(): void
    {
        $reservation = $this->reserve()->json('data');
        $attempts = [
            fn () => DB::table('patient_number_reservations')->where('id', $reservation['id'])->update(['number' => 'PVT-20']),
            fn () => DB::table('patient_number_reservations')->where('id', $reservation['id'])->delete(),
            fn () => DB::table('patient_number_sequences')->where('prefix', 'PVT')->update(['last_value' => 0]),
            fn () => DB::table('patient_number_sequences')->where('prefix', 'PVT')->delete(),
        ];
        foreach ($attempts as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The database must reject number-history mutation.');
            } catch (QueryException) {
                $this->assertSame('PVT-01', DB::table('patient_number_reservations')->where('id', $reservation['id'])->value('number'));
            }
        }
    }

    private function category(string $code): int
    {
        return PatientCategory::where('code', $code)->sole()->id;
    }

    private function reserve(string $category = 'private', ?string $key = null): \Illuminate\Testing\TestResponse
    {
        return $this->postCommand('/api/v1/patients/number-reservations', ['category_id' => $this->category($category)],
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function registration(array $reservation): array
    {
        return ['first_name' => 'Example', 'last_name' => 'Patient', 'category_id' => $reservation['category_id'],
            'number_reservation_token' => $reservation['number_reservation_token']];
    }
}
