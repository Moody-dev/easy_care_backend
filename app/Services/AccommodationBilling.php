<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\AccommodationAssessment;
use App\Models\Admission;
use App\Models\AllocationRate;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\BillingAccount;
use App\Models\Encounter;
use App\Models\User;
use App\Support\AccommodationMoney;
use App\Support\BillingMoney;
use App\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccommodationBilling
{
    public function __construct(private AuditTrail $audit) {}

    public function capture(BedAllocation $allocation, Bed $bed, User $actor, CarbonInterface $time): void
    {
        $ward = $bed->ward;
        $rate = $bed->daily_rate ?? $ward->daily_rate;
        $record = new AllocationRate;
        $record->forceFill(['allocation_id' => $allocation->id, 'daily_rate_kobo' => $rate === null ? null : BillingMoney::parse($rate),
            'source' => $bed->daily_rate !== null ? 'bed' : ($rate !== null ? 'ward' : 'missing'),
            'source_version' => $bed->daily_rate !== null ? $bed->version : ($rate !== null ? $ward->version : null),
            'actor_id' => $actor->id, 'recorded_at' => $time])->save();
    }

    /** @return array<string, mixed> */
    public function calculate(Admission $admission, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= $admission->discharged_at ?? CarbonImmutable::now('UTC');
        $allocations = $admission->allocations()->with(['rate' => fn ($query) => $query->lockForUpdate(), 'bed.ward'])->orderBy('assigned_at')->orderBy('id')->lockForUpdate()->get();
        $occupied = 0;
        $durations = [];
        $previous = null;
        foreach ($allocations as $allocation) {
            $start = $this->micros($allocation->assigned_at);
            $end = $this->micros($allocation->released_at ?? $asOf);
            if ($end < $start || $end > $this->micros($asOf) || $previous !== null && $start < $previous) {
                throw new WriteConflict('accommodation_clock_conflict', 'Bed assignment times overlap or exceed the calculation time.');
            }
            $durations[$allocation->id] = $end - $start;
            $occupied += $end - $start;
            $previous = $end;
        }
        $days = intdiv($occupied, AccommodationMoney::DAY_MICROS);
        $remaining = $days * AccommodationMoney::DAY_MICROS;
        $items = $issues = [];
        $total = 0;
        foreach ($allocations as $allocation) {
            $used = min($remaining, $durations[$allocation->id]);
            $remaining -= $used;
            $rate = $allocation->rate?->daily_rate_kobo;
            $amount = 0;
            if ($used > 0 && $rate === null) {
                $issues[] = ['allocation_id' => $allocation->id, 'code' => 'historical_rate_missing'];
                $amount = null;
            } elseif ($used > 0) {
                try {
                    $amount = AccommodationMoney::portion($rate, $used);
                    $total += $amount;
                    if ($total > intdiv(FixedPoint::MAX, 10000)) {
                        throw new InvalidArgumentException;
                    }
                } catch (InvalidArgumentException) {
                    $amount = null;
                    $issues[] = ['allocation_id' => $allocation->id, 'code' => 'amount_out_of_range'];
                }
            }
            $items[] = ['allocation_id' => $allocation->id, 'bed_id' => $allocation->bed_id,
                'ward' => $allocation->bed->ward->name, 'bed' => $allocation->bed->label,
                'assigned_at' => $allocation->assigned_at->toISOString(), 'released_at' => $allocation->released_at?->toISOString(),
                'occupied_microseconds' => $durations[$allocation->id], 'billable_microseconds' => $used,
                'daily_rate' => $rate === null ? null : BillingMoney::format($rate),
                'amount' => $amount === null ? null : BillingMoney::format($amount)];
        }

        return ['currency' => 'NGN', 'policy' => 'completed_24h_occupied_time_across_transfers',
            'rounding' => 'half_up_2dp_per_allocation', 'as_of' => $asOf->toISOString(), 'occupied_microseconds' => $occupied,
            'completed_days' => $days, 'unbilled_microseconds' => $occupied % AccommodationMoney::DAY_MICROS,
            'status' => $issues === [] ? 'ready' : 'requires_review', 'total' => $issues === [] ? BillingMoney::format($total) : null,
            'items' => $items, 'issues' => $issues];
    }

    public function finalize(Admission $admission, User $actor, CarbonInterface $time): AccommodationAssessment
    {
        $saved = $admission->assessment()->first();
        if ($saved !== null) {
            return $saved;
        }
        $snapshot = $this->calculate($admission, $time);
        $snapshot['exit_type'] = DB::table('admission_exit_types')->where('admission_id', $admission->id)->value('exit_type') ?? 'discharge';
        $assessment = new AccommodationAssessment;
        $assessment->forceFill(['admission_id' => $admission->id, 'source_key' => hash('sha256', 'accommodation|'.$admission->id),
            'status' => $snapshot['status'], 'total_kobo' => $snapshot['total'] === null ? null : BillingMoney::parse($snapshot['total']),
            'snapshot' => $snapshot, 'actor_id' => $actor->id, 'recorded_at' => $time])->save();
        $this->audit->record('admission.accommodation_assessed', $actor->id, 'admission', $admission->id);

        return $assessment;
    }

    /** @return array<string, mixed> */
    public function invoiceLine(BillingAccount $account, Encounter $visit, int $id, int $expectedPrice, CarbonInterface $time): array
    {
        $row = AccommodationAssessment::query()->whereHas('admission', fn ($query) => $query->where('patient_id', $account->patient_id)
            ->where('encounter_id', $visit->id))->lockForUpdate()->findOrFail($id);
        if ($row->recorded_at->greaterThan($time)) {
            throw new WriteConflict('billing_clock_conflict', 'The server time precedes the saved accommodation assessment.');
        }
        if ($row->status !== 'ready') {
            throw new WriteConflict('accommodation_requires_review', 'Resolve the missing historical bed rate before billing this stay.');
        }
        if ($expectedPrice !== $row->id) {
            throw new WriteConflict('version_conflict', 'Reload the finalized accommodation assessment.');
        }
        if (DB::table('billing_invoice_items')->where('source_key', $row->source_key)->lockForUpdate()->exists()) {
            throw new WriteConflict('billing_source_charged', 'This admission already has a retained accommodation charge.');
        }

        return ['source_type' => 'accommodation', 'source_id' => $row->id, 'source_key' => $row->source_key,
            'service_price_id' => null, 'drug_price_id' => null, 'corrects_item_id' => null,
            'quantity_micros' => FixedPoint::SCALE, 'unit_price_micros' => $row->total_kobo * 10000, 'amount_kobo' => $row->total_kobo,
            'description' => 'Accommodation: '.$row->snapshot['completed_days'].' completed day(s)',
            'snapshot' => $row->snapshot + ['assessment_id' => $row->id, 'self_pay_confirmed' => true]];
    }

    private function micros(CarbonInterface $time): int
    {
        return (int) $time->format('U') * 1000000 + (int) $time->format('u');
    }
}
