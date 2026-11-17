<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\ClinicalRecord;
use App\Models\Drug;
use App\Models\DrugBatch;
use App\Models\Encounter;
use App\Models\MedicationAuthorization;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use App\Models\StockMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

class MedicationFulfillment
{
    public const PERMISSIONS = ['authorize' => 'medications.authorize', 'dispense' => 'medications.dispense',
        'return-request' => 'medications.returns.request', 'return-approve' => 'medications.returns.approve',
        'return-reject' => 'medications.returns.approve', 'administer' => 'medications.administer', 'correct' => 'medications.correct'];

    public function __construct(private StockAuthority $authority, private AuditTrail $audit) {}

    public static function isCommand(string $route): bool
    {
        return str_starts_with($route, 'api.v1.medications.') && isset(self::PERMISSIONS[substr($route, strrpos($route, '.') + 1)]);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        foreach (['patients.view', 'encounters.view', 'prescriptions.view', 'medications.view', self::PERMISSIONS[self::action($request)]] as $permission) {
            $this->authority->permission($actor, $permission);
        }
        $this->authority->password($request, $actor);
        $action = self::action($request);
        $authorization = $action === 'authorize' ? null : MedicationAuthorization::query()->visibleTo($actor)->findOrFail($request->route('medication'));
        $item = $authorization?->item ?? PrescriptionItem::query()->findOrFail($this->identifier($request, 'prescription_item_id'));
        $record = $item->prescription->record;
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($record->patient_id);
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($record->encounter_id);
        $record = ClinicalRecord::query()->prescriptionsVisibleTo($actor)->lockForUpdate()->findOrFail($record->id);
        if ($authorization !== null) {
            $authorization = MedicationAuthorization::query()->lockForUpdate()->findOrFail($authorization->id);
        }
        if ($action === 'authorize') {
            $this->authority->permission($actor, 'stock.catalog.view');
            abort_unless($actor->roles()->where('code', 'doctor')->lockForUpdate()->first() !== null, 403);
            $assignment = $visit->assignments()->current()->lockForUpdate()->first();
            abort_unless($assignment !== null && $assignment->doctor_id === $actor->id, 403);
            $request->attributes->set('medication_assignment', $assignment);
        } elseif (in_array($action, ['administer', 'correct'], true)) {
            abort_unless($actor->roles()->where('code', 'nurse')->lockForUpdate()->first() !== null, 403);
        } else {
            $this->authority->permission($actor, 'stock.view');
        }
        if ($action === 'dispense') {
            $this->authority->stores($actor, [$this->identifier($request, 'store_id')]);
        } elseif ($authorization !== null) {
            $dispense = DB::table('medication_dispenses')->where('authorization_id', $authorization->id)->find($this->identifier($request, 'dispense_id'));
            abort_if($dispense === null, 404);
            if (str_starts_with($action, 'return-')) {
                $this->authority->stores($actor, [(int) $dispense->store_id], $action !== 'return-reject');
                if ($action !== 'return-request') {
                    $return = DB::table('medication_returns')->where('dispense_id', $dispense->id)->find($this->identifier($request, 'return_id'));
                    abort_if($return === null, 404);
                    abort_if($return->actor_id === $actor->id, 403);
                }
            }
            if ($action === 'correct') {
                $previous = DB::table('medication_administrations')->where('dispense_id', $dispense->id)->find($this->identifier($request, 'administration_id'));
                abort_if($previous === null, 404);
                abort_unless($previous->actor_id === $actor->id, 403);
            }
        }
        $request->attributes->set('medication_record', $record);
        $request->attributes->set('medication_visit', $visit);
        $request->attributes->set('medication_authorization', $authorization);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): MedicationAuthorization
    {
        $action = self::action($request);
        $record = $request->attributes->get('medication_record');
        $visit = $request->attributes->get('medication_visit');
        $authorization = $request->attributes->get('medication_authorization');
        $this->authority->version($visit->version, $data['encounter_version']);
        if (! in_array($visit->status, ['open', 'in_progress'], true)) {
            throw new WriteConflict('medication_visit_closed', 'Closed visits are read-only.');
        }
        if (in_array($action, ['authorize', 'dispense', 'administer'], true)) {
            if ($record->status !== 'signed' || $record->prescription->order_status !== 'active'
                || $record->amendment()->finalized()->lockForUpdate()->first() !== null) {
                throw new WriteConflict('prescription_not_active', 'Use a current signed active prescription.');
            }
        }
        $last = $authorization?->events()->orderByDesc('version')->lockForUpdate()->first();
        $time = $this->authority->time($visit->opened_at, $record->finalized_at, $last?->recorded_at);
        $version = $last?->version ?? 0;
        if ($authorization !== null) {
            $this->authority->version($version, $data['version']);
        }
        $signature = ['actor_id' => $actor->id, 'recorded_at' => $time->format('Y-m-d H:i:s.u'), 'reason' => $data['reason']];
        if ($action === 'authorize') {
            $item = $record->prescription->items()->findOrFail($data['prescription_item_id']);
            $this->authority->version($record->version, $data['prescription_version']);
            if (MedicationAuthorization::query()->where('prescription_item_id', $item->id)->lockForUpdate()->first() !== null) {
                throw new WriteConflict('medication_already_authorized', 'This prescription line already has a signed catalog authorization.');
            }
            $ancestor = $record;
            while ($ancestor->amends_id !== null) {
                $ancestor = ClinicalRecord::query()->findOrFail($ancestor->amends_id);
                if (DB::table('medication_dispenses as d')->join('medication_authorizations as a', 'a.id', '=', 'd.authorization_id')
                    ->join('prescription_items as i', 'i.id', '=', 'a.prescription_item_id')->where('i.prescription_id', $ancestor->id)->lockForUpdate()->first() !== null) {
                    throw new WriteConflict('prescription_already_fulfilled', 'An earlier version has issued medication. A replacement must not reset its allowance; create an explicitly reviewed new order instead.');
                }
            }
            $assignment = $request->attributes->get('medication_assignment');
            abort_unless($assignment->id === (int) $data['assignment_id'], 409);
            $this->authority->version($assignment->version, $data['assignment_version']);
            $drug = Drug::query()->lockForUpdate()->findOrFail($data['drug_id']);
            $this->authority->version($drug->version, $data['drug_version']);
            $this->availableDrug($drug);
            $quantity = $this->quantity($data['maximum_quantity'], $drug, 'maximum_quantity');
            $this->authority->time($assignment->assigned_at, $assignment->accepted_at, $drug->updated_at);
            $authorization = new MedicationAuthorization;
            $authorization->forceFill(['prescription_item_id' => $item->id, 'drug_id' => $drug->id, 'drug_version' => $drug->version,
                'assignment_id' => $assignment->id, 'assignment_version' => $assignment->version, 'maximum_quantity_micros' => $quantity,
                'snapshot' => ['prescription' => $item->only(PrescriptionPayloads::ITEM_FIELDS), 'drug' => StockCatalog::snapshot('drugs', $drug)]] + $signature)->save();
            $recordId = $authorization->id;
        } elseif ($action === 'dispense') {
            $drug = Drug::query()->lockForUpdate()->findOrFail($authorization->drug_id);
            $this->authority->version($drug->version, $data['drug_version']);
            $this->availableDrug($drug);
            $quantity = $this->quantity($data['quantity'], $drug);
            $issued = (int) DB::table('medication_dispenses')->where('authorization_id', $authorization->id)->lockForUpdate()->get()->sum('quantity_micros');
            if ($quantity > $authorization->maximum_quantity_micros - $issued) {
                throw new WriteConflict('prescription_quantity_exceeded', 'This issue exceeds the remaining signed authorization. Returns do not reset its allowance.');
            }
            $store = $this->authority->stores($actor, [(int) $data['store_id']])[(int) $data['store_id']];
            $this->authority->version($store->version, $data['store_version']);
            $batch = DrugBatch::query()->where('drug_id', $drug->id)->lockForUpdate()->findOrFail($data['batch_id']);
            if (! StockCatalog::approved('batches', $batch) || $batch->expires_on->toDateString() <= $this->authority->today()) {
                throw new WriteConflict('medication_batch_unavailable', 'Choose an approved, unexpired batch.');
            }
            $head = DB::table('stock_position_heads')->where('batch_id', $batch->id)->where('store_id', $store->id)->lockForUpdate()->first();
            $this->authority->version((int) ($head->movement_count ?? 0), $data['balance_version']);
            if ($quantity > (int) ($head->quantity_micros ?? 0)) {
                throw new WriteConflict('stock_insufficient', 'The selected stock cannot cover this issue.');
            }
            $this->authority->time($store->updated_at, $drug->updated_at, $batch->recorded_at, $head?->recorded_at);
            $movement = $this->movement($authorization, $batch->id, $store->id, -$quantity, 'dispense', $signature);
            $recordId = DB::table('medication_dispenses')->insertGetId(['authorization_id' => $authorization->id,
                'batch_id' => $batch->id, 'store_id' => $store->id, 'quantity_micros' => $quantity, 'movement_id' => $movement->id] + $signature);
        } else {
            $dispense = DB::table('medication_dispenses')->where('authorization_id', $authorization->id)->lockForUpdate()->find($data['dispense_id']);
            abort_if($dispense === null, 404);
            $quantity = isset($data['quantity']) ? $this->quantity($data['quantity'], $authorization->drug, 'quantity', $action !== 'correct') : 0;
            if (str_starts_with($action, 'return-')) {
                $this->authority->stores($actor, [(int) $dispense->store_id], $action !== 'return-reject');
                $recordId = $this->returnMedication($action, $authorization, $dispense, $data, $quantity, $signature);
            } else {
                $recordId = $this->administer($action, $dispense, $data, $quantity, $signature, $time);
            }
        }
        $eventId = DB::table('medication_events')->insertGetId(['authorization_id' => $authorization->id, 'version' => $version + 1,
            'action' => $action, 'record_id' => $recordId] + $signature);
        $this->audit->record('medication.'.$action, $actor->id, 'medication', $authorization->id, ['version' => $version + 1, 'record_id' => $eventId]);

        return $authorization;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $signature */
    private function returnMedication(string $action, MedicationAuthorization $authorization, stdClass $dispense, array $data, int $quantity, array $signature): int
    {
        $batch = DrugBatch::query()->lockForUpdate()->findOrFail($dispense->batch_id);
        if ($action === 'return-request') {
            if ($quantity > $dispense->quantity_micros - $this->unavailable($dispense->id)) {
                throw new WriteConflict('return_quantity_exceeded', 'Only issued, unconsumed and unreturned quantities can be returned.');
            }
            if ($data['disposition'] === 'usable' && ! $data['usable_confirmed']
                || $data['disposition'] !== 'usable' && $data['usable_confirmed']) {
                throw ValidationException::withMessages(['usable_confirmed' => ['Explicitly confirm usability only for a usable return.']]);
            }
            $this->returnExpiry($data['disposition'], $batch);

            return DB::table('medication_returns')->insertGetId(['dispense_id' => $dispense->id, 'quantity_micros' => $quantity,
                'disposition' => $data['disposition'], 'usable_confirmed' => $data['usable_confirmed']] + $signature);
        }
        $return = DB::table('medication_returns')->where('dispense_id', $dispense->id)->lockForUpdate()->find($data['return_id']);
        abort_if($return === null, 404);
        abort_if($return->actor_id === $signature['actor_id'], 403);
        if (DB::table('medication_return_reviews')->where('return_id', $return->id)->lockForUpdate()->first() !== null) {
            throw new WriteConflict('return_already_reviewed', 'This return already has an independent decision.');
        }
        $movement = null;
        if ($action === 'return-approve') {
            $this->returnExpiry($return->disposition, $batch);
            if ($return->disposition === 'usable') {
                $this->availableDrug($authorization->drug);
                $movement = $this->movement($authorization, $batch->id, $dispense->store_id, (int) $return->quantity_micros, 'return', $signature);
            }
        }

        return DB::table('medication_return_reviews')->insertGetId(['return_id' => $return->id,
            'decision' => $action === 'return-approve' ? 'approved' : 'rejected', 'movement_id' => $movement?->id] + $signature);
    }

    private function returnExpiry(string $disposition, DrugBatch $batch): void
    {
        $expired = $batch->expires_on->toDateString() <= $this->authority->today();
        if ($disposition === 'usable' && $expired || $disposition === 'expired' && ! $expired) {
            throw new WriteConflict('return_expiry_conflict', 'The return disposition does not match the Lagos expiry date.');
        }
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $signature */
    private function administer(string $action, stdClass $dispense, array $data, int $quantity, array $signature, CarbonImmutable $time): int
    {
        $previous = null;
        if ($action === 'correct') {
            $previous = DB::table('medication_administrations')->where('dispense_id', $dispense->id)->lockForUpdate()->find($data['administration_id']);
            abort_if($previous === null, 404);
            abort_unless($previous->actor_id === $signature['actor_id'], 403);
            if (DB::table('medication_administrations')->where('corrects_id', $previous->id)->lockForUpdate()->first() !== null) {
                throw new WriteConflict('administration_superseded', 'Correct the current signed administration entry.');
            }
        }
        $observed = CarbonImmutable::parse($data['observed_at'])->utc();
        $batch = DrugBatch::query()->findOrFail($dispense->batch_id);
        if ($observed->greaterThan($time) || $observed->lessThan($dispense->recorded_at)
            || $quantity > 0 && $observed->setTimezone('Africa/Lagos')->toDateString() >= $batch->expires_on->toDateString()) {
            throw ValidationException::withMessages(['observed_at' => ['Record an actual observation after issue, no later than now, and before batch expiry.']]);
        }
        $available = $dispense->quantity_micros - $this->unavailable($dispense->id) + (int) ($previous->quantity_micros ?? 0);
        if ($quantity > $available) {
            throw new WriteConflict('administration_quantity_exceeded', 'This administration exceeds the remaining issued quantity.');
        }

        return DB::table('medication_administrations')->insertGetId(['dispense_id' => $dispense->id, 'corrects_id' => $previous?->id,
            'quantity_micros' => $quantity, 'observed_at' => $observed->format('Y-m-d H:i:s.u')] + $signature);
    }

    private function unavailable(int $dispenseId): int
    {
        $returns = DB::table('medication_returns')->where('dispense_id', $dispenseId)->lockForUpdate()->get();
        $rejected = DB::table('medication_return_reviews')->whereIn('return_id', $returns->pluck('id'))->where('decision', 'rejected')
            ->lockForUpdate()->get()->pluck('return_id');
        $administrations = DB::table('medication_administrations')->where('dispense_id', $dispenseId)->lockForUpdate()->get();
        $superseded = $administrations->pluck('corrects_id')->filter();

        return (int) $returns->whereNotIn('id', $rejected)->sum('quantity_micros')
            + (int) $administrations->whereNotIn('id', $superseded)->sum('quantity_micros');
    }

    private function availableDrug(Drug $drug): void
    {
        if (! $drug->is_active || ! StockCatalog::approved('drugs', $drug)) {
            throw new WriteConflict('medication_catalog_unavailable', 'Select a current approved active medication.');
        }
    }

    private function quantity(mixed $value, Drug $drug, string $field = 'quantity', bool $positive = true): int
    {
        $quantity = $this->authority->decimal($value, $field, $positive);
        if ($quantity % (10 ** (6 - $drug->quantity_scale)) !== 0) {
            throw ValidationException::withMessages([$field => ['Use the approved medication base-unit precision.']]);
        }

        return $quantity;
    }

    private function identifier(Request $request, string $field): int
    {
        $value = $request->input($field);
        if ((! is_int($value) && ! is_string($value)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value) !== 1) {
            throw ValidationException::withMessages([$field => ['Supply a positive record ID within the supported range.']]);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $signature */
    private function movement(MedicationAuthorization $authorization, int $batch, int $store, int $quantity, string $kind, array $signature): StockMovement
    {
        $head = DB::table('stock_position_heads')->where('batch_id', $batch)->where('store_id', $store)->lockForUpdate()->first();
        $this->authority->time($head?->recorded_at);
        $movement = new StockMovement;
        $movement->forceFill(['movement_key' => hash('sha256', 'medication|'.\Illuminate\Support\Str::uuid()),
            'drug_id' => $authorization->drug_id, 'batch_id' => $batch, 'store_id' => $store, 'kind' => $kind,
            'quantity_micros' => $quantity, 'reference_type' => 'medication_authorization', 'reference_id' => (string) $authorization->id,
            'created_by' => $signature['actor_id'], 'recorded_at' => $signature['recorded_at'], 'reason' => $signature['reason']])->save();

        return $movement;
    }

    public function response(MedicationAuthorization $authorization): JsonResponse
    {
        return (new \App\Http\Resources\MedicationAuthorizationResource($authorization))->response();
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        $authorization = MedicationAuthorization::query()->visibleTo($actor)->lockForUpdate()->findOrFail($receipt->resource_id);
        $this->authority->version($authorization->events()->orderByDesc('version')->lockForUpdate()->firstOrFail()->version, $receipt->resource_version);

        return $this->response($authorization);
    }

    public function assertVisitCanEnd(Encounter $visit): void
    {
        $last = DB::table('medication_events as e')->join('medication_authorizations as a', 'a.id', '=', 'e.authorization_id')
            ->join('prescription_items as i', 'i.id', '=', 'a.prescription_item_id')->join('clinical_records as c', 'c.id', '=', 'i.prescription_id')
            ->where('c.encounter_id', $visit->id)->orderByDesc('e.recorded_at')->select('e.recorded_at')->lockForUpdate()->first();
        if ($last !== null) {
            $this->authority->time($last->recorded_at);
        }
        $pending = DB::table('medication_returns as r')->join('medication_dispenses as d', 'd.id', '=', 'r.dispense_id')
            ->join('medication_authorizations as a', 'a.id', '=', 'd.authorization_id')->join('prescription_items as i', 'i.id', '=', 'a.prescription_item_id')
            ->join('clinical_records as c', 'c.id', '=', 'i.prescription_id')->where('c.encounter_id', $visit->id)
            ->whereNotExists(fn (\Illuminate\Database\Query\Builder $query): \Illuminate\Database\Query\Builder => $query->selectRaw('1')
                ->from('medication_return_reviews as v')->whereColumn('v.return_id', 'r.id')->lockForUpdate())->lockForUpdate()->first();
        if ($pending !== null) {
            throw new WriteConflict('encounter_pending_medication_returns', 'Review pending medication returns before ending this visit.');
        }
    }
}
