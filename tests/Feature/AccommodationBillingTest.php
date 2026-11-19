<?php

namespace Tests\Feature;

use App\Models\AccommodationAssessment;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\Encounter;
use App\Services\AccommodationBilling;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\BillingTestCase;

class AccommodationBillingTest extends BillingTestCase
{
    use RefreshDatabase;

    private function stay(): Admission
    {
        return Admission::factory()->forEncounter($this->visit)->create();
    }

    private function occupy(Admission $admission, int $hours, string $rate = '10000.00', bool $capture = true): void
    {
        $bed = Bed::factory()->create(['daily_rate' => $rate]);
        $row = BedAllocation::factory()->create(['admission_id' => $admission->id, 'bed_id' => $bed->id,
            'assigned_by' => $this->cashier->id, 'assigned_at' => now('UTC')]);
        if ($capture) {
            app(AccommodationBilling::class)->capture($row, $bed, $this->cashier, now('UTC'));
        }
        $this->travel($hours)->hours();
        $row->forceFill(['released_at' => now('UTC'), 'released_by' => $this->cashier->id,
            'release_reason' => 'Synthetic bed release', 'version' => 2])->save();
    }

    private function finish(Admission $admission): AccommodationAssessment
    {
        $admission->forceFill(['status' => 'discharge_authorized', 'version' => 2, 'authorized_at' => now('UTC'),
            'authorized_by' => $this->cashier->id, 'discharge_reason' => 'Synthetic exit reason'])->save();
        $admission->forceFill(['status' => 'discharged', 'version' => 3, 'discharged_at' => now('UTC'),
            'discharged_by' => $this->reviewer->id])->save();

        return app(AccommodationBilling::class)->finalize($admission, $this->reviewer, now('UTC'));
    }

    #[DataProvider('durations')]
    public function test_only_completed_occupied_days_are_chargeable(int $hours, int $days, string $amount): void
    {
        $admission = $this->stay();
        $this->occupy($admission, $hours);
        $calculation = app(AccommodationBilling::class)->calculate($admission);
        $this->assertSame($days, $calculation['completed_days']);
        $this->assertSame($amount, $calculation['total']);
    }

    public static function durations(): array
    {
        return [[0, 0, '0.00'], [23, 0, '0.00'], [24, 1, '10000.00'], [30, 1, '10000.00'], [48, 2, '20000.00']];
    }

    public function test_one_microsecond_before_a_day_is_free_and_the_exact_boundary_is_billable(): void
    {
        $admission = $this->stay();
        $bed = Bed::factory()->create(['daily_rate' => '10000.00']);
        $allocation = BedAllocation::factory()->create(['admission_id' => $admission->id, 'bed_id' => $bed->id,
            'assigned_by' => $this->cashier->id, 'assigned_at' => now('UTC')]);
        \App\Models\AllocationRate::factory()->create(['allocation_id' => $allocation->id]);
        $this->travelTo(now('UTC')->addDay()->subMicrosecond());
        $this->assertSame('0.00', app(AccommodationBilling::class)->calculate($admission)['total']);
        $this->travelTo(now('UTC')->addMicrosecond());
        $this->assertSame('10000.00', app(AccommodationBilling::class)->calculate($admission)['total']);
    }

    public function test_zero_day_exit_can_be_explicitly_invoiced_without_a_minimum_charge(): void
    {
        $assessment = $this->finish($this->stay());
        $this->writeBill(0, '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 1, 'self_pay_confirmed' => true,
            'sources' => [['type' => 'accommodation', 'id' => $assessment->id, 'price_id' => $assessment->id]]])->assertCreated()
            ->assertJsonPath('data.totals.charges', '0.00');
    }

    public function test_unallocated_time_does_not_complete_a_billable_day(): void
    {
        $admission = $this->stay();
        $this->occupy($admission, 12);
        $this->travel(48)->hours();
        $this->occupy($admission, 11, '20000.00');
        $this->assertSame('0.00', app(AccommodationBilling::class)->calculate($admission)['total']);
    }

    public function test_cashier_can_bill_the_frozen_stay_once_and_review_a_reasoned_correction(): void
    {
        $admission = $this->stay();
        $this->occupy($admission, 30);
        $assessment = $this->finish($admission);
        $this->loginCashier();
        $this->getJson($this->path('/accommodation'))->assertOk()->assertJsonPath('data.0.price_id', $assessment->id)
            ->assertJsonPath('data.0.calculation.total', '10000.00');
        $data = ['encounter_id' => $this->visit->id, 'encounter_version' => 1, 'self_pay_confirmed' => true,
            'sources' => [['type' => 'accommodation', 'id' => $assessment->id, 'price_id' => $assessment->id]]];
        $headers = ['Idempotency-Key' => 'synthetic-accommodation-invoice'];
        $invoice = $this->writeBill(0, '/invoices', $data, headers: $headers)->assertCreated()
            ->assertJsonPath('data.totals.outstanding', '10000.00')->json('data');
        $this->writeBill(0, '/invoices', $data, headers: $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->writeBill($invoice['version'], '/invoices', $data)->assertConflict()->assertJsonPath('code', 'billing_source_charged');
        $item = DB::table('billing_invoice_items')->sole();
        $pending = $this->writeBill($invoice['version'], '/credits', ['item_id' => $item->id, 'amount' => '10000.00',
            'replacement_quantity' => '0.5'])->assertCreated()->json('data');
        $this->writeBill($pending['version'], '/changes/'.$pending['last_record_id'].'/approve', [], $this->reviewer)->assertCreated()
            ->assertJsonPath('data.totals.outstanding', '5000.00');
        $this->assertDatabaseHas('billing_invoice_items', ['id' => $item->id, 'amount_kobo' => 1000000]);
        $this->getJson($this->path('/reconciliation'))->assertOk()->assertJsonPath('data.balanced', true);
    }

    public function test_assessments_cannot_cross_patient_boundaries_and_restricted_patients_are_hidden(): void
    {
        $admission = $this->stay();
        $this->occupy($admission, 24);
        $assessment = $this->finish($admission);
        $original = $this->visit;
        $this->visit = Encounter::factory()->create();
        $this->writeBill(0, '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'self_pay_confirmed' => true, 'sources' => [['type' => 'accommodation', 'id' => $assessment->id, 'price_id' => $assessment->id]]])->assertNotFound();
        $this->assertDatabaseCount('billing_accounts', 0);
        $this->visit = $original;
        $original->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson($this->path('/accommodation'))->assertNotFound();
    }

    public function test_retained_rates_assessments_and_unit_confirmations_cannot_be_rewritten(): void
    {
        $admission = $this->stay();
        $this->occupy($admission, 24);
        $this->finish($admission);
        foreach (['allocation_rates', 'accommodation_assessments'] as $table) {
            try {
                DB::table($table)->delete();
                $this->fail('Retained billing facts must not be deleted.');
            } catch (QueryException) {
                $this->assertDatabaseCount($table, 1);
            }
        }
    }

    public function test_legacy_missing_rate_requires_review_and_is_not_inferred_from_current_prices(): void
    {
        $admission = $this->stay();
        $this->occupy($admission, 25, capture: false);
        $assessment = $this->finish($admission);
        $this->assertSame('requires_review', $assessment->status);
        $this->writeBill(0, '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 1, 'self_pay_confirmed' => true,
            'sources' => [['type' => 'accommodation', 'id' => $assessment->id, 'price_id' => $assessment->id]]])->assertConflict()
            ->assertJsonPath('code', 'accommodation_requires_review');
    }
}
