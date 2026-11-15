<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\AdmissionResource;
use App\Http\Resources\BedResource;
use App\Http\Resources\WardResource;
use App\Models\Admission;
use App\Models\AdmissionRevision;
use App\Models\Bed;
use App\Models\BedAllocation;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Models\Ward;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;

class AdmissionFlow
{
    public const ACTIONS = [
        'store' => ['admissions.create', 'admitted'],
        'allocate' => ['admissions.allocate', 'allocated'],
        'transfer' => ['admissions.allocate', 'transferred'],
        'release' => ['admissions.allocate', 'released'],
        'authorize-discharge' => ['admissions.authorize_discharge', 'discharge_authorized'],
        'complete-discharge' => ['admissions.complete_discharge', 'discharged'],
    ];

    public function __construct(private AuditTrail $audit) {}

    public static function isCommand(string $route): bool
    {
        return (str_starts_with($route, 'api.v1.admissions.') && array_key_exists(substr($route, strrpos($route, '.') + 1), self::ACTIONS))
            || (str_starts_with($route, 'api.v1.locations.') && (str_ends_with($route, '.store') || str_ends_with($route, '.update')));
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public static function isLocation(Request $request): bool
    {
        return str_starts_with($request->route()->getName(), 'api.v1.locations.');
    }

    public static function resourceType(Request $request): string
    {
        return self::isLocation($request) ? (str_contains($request->route()->getName(), '.beds.') ? 'bed' : 'ward') : 'admission';
    }

    public function authorize(Request $request, User $actor): void
    {
        abort_unless($actor->is_active, 403);
        if (self::isLocation($request)) {
            Gate::forUser($actor)->authorize('locations.view');
            Gate::forUser($actor)->authorize('locations.manage');
            if (self::action($request) === 'update') {
                $class = self::resourceType($request) === 'bed' ? Bed::class : Ward::class;
                $record = $class::query()->lockForUpdate()->findOrFail($request->route('location'));
                $request->attributes->set('location_record', $record);
            }

            return;
        }
        foreach (['admissions.view', 'encounters.view', 'patients.view', self::ACTIONS[self::action($request)][0]] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        $admission = self::action($request) === 'store' ? null
            : Admission::query()->visibleTo($actor)->findOrFail($request->route('admission'));
        $visitId = $admission?->encounter_id ?? $request->route('encounter');
        $visit = Encounter::query()->visibleTo($actor)->findOrFail($visitId);
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visit->patient_id);
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visitId);
        if ($admission !== null) {
            $admission = Admission::query()->visibleTo($actor)->lockForUpdate()->findOrFail($admission->id);
        }
        $action = self::action($request);
        if ($action === 'authorize-discharge') {
            abort_unless($actor->roles()->where('code', 'doctor')->exists(), 403);
            abort_unless($visit->assignments()->current()->where('doctor_id', $actor->id)->lockForUpdate()->exists(), 403);
        }
        if ($action === 'complete-discharge') {
            abort_unless($actor->roles()->where('code', 'nurse')->exists(), 403);
            abort_if($admission->authorized_by === $actor->id, 403);
        }
        if (in_array($action, ['allocate', 'transfer'], true)) {
            Gate::forUser($actor)->authorize('locations.view');
        }
        $request->attributes->set('admission_record', $admission);
        $request->attributes->set('admission_encounter', $visit);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): Admission|Ward|Bed
    {
        if (self::isLocation($request)) {
            return $this->location($request, $actor, $data);
        }
        $visit = $request->attributes->get('admission_encounter');
        $this->version($visit->version, $data['encounter_version']);
        if (! in_array($visit->status, ['open', 'in_progress'], true)) {
            throw new WriteConflict('encounter_terminal', 'This visit is already completed or cancelled.');
        }
        $time = now('UTC');
        $this->clock($time, $visit->opened_at);
        $assignment = $visit->assignments()->current()->lockForUpdate()->first();
        if ($assignment !== null) {
            $this->clock($time, $assignment->assigned_at, $assignment->accepted_at);
        }
        $action = self::action($request);
        if ($action === 'store') {
            $patient = Patient::query()->lockForUpdate()->findOrFail($visit->patient_id);
            $this->version($patient->version, $data['patient_version']);
            if (Admission::query()->where('encounter_id', $visit->id)->lockForUpdate()->exists()
                || Admission::query()->where('patient_id', $patient->id)->active()->lockForUpdate()->exists()) {
                throw new WriteConflict('admission_exists', 'This patient or visit already has an admission. Reload the saved episode.');
            }
            $last = Admission::query()->where('patient_id', $patient->id)->orderByDesc('discharged_at')->lockForUpdate()->first();
            $this->clock($time, $last?->discharged_at);
            $admission = new Admission;
            $admission->forceFill(['encounter_id' => $visit->id, 'patient_id' => $patient->id,
                'admitted_by' => $actor->id, 'admitted_at' => $time])->save();
        } else {
            $admission = $request->attributes->get('admission_record');
            $this->version($admission->version, $data['version']);
            $latest = $admission->revisions()->reorder()->orderByDesc('workflow_version')->lockForUpdate()->first();
            $this->version($latest?->workflow_version ?? 0, $data['workflow_version']);
            if ($admission->status === 'discharged') {
                throw new WriteConflict('admission_terminal', 'This admission is discharged and cannot be changed.');
            }
            $this->clock($time, $admission->admitted_at, $admission->authorized_at, $latest?->recorded_at);
            $current = $admission->allocations()->current()->lockForUpdate()->first();
            foreach ($admission->allocations()->lockForUpdate()->get() as $allocation) {
                $this->clock($time, $allocation->assigned_at, $allocation->released_at);
            }
            if (in_array($action, ['transfer', 'release', 'complete-discharge'], true)) {
                if ($current?->id !== ($data['allocation_id'] === null ? null : (int) $data['allocation_id'])
                    || $current?->version !== ($data['allocation_version'] === null ? null : (int) $data['allocation_version'])) {
                    throw new WriteConflict('allocation_conflict', 'The current bed allocation has changed. Reload this admission.');
                }
            }
            if (in_array($action, ['allocate', 'transfer'], true)) {
                if ($action === 'allocate' && $current !== null) {
                    throw new WriteConflict('allocation_exists', 'This patient already has a bed. Use a transfer.');
                }
                if ($current?->bed_id === (int) $data['bed_id']) {
                    throw new WriteConflict('allocation_unchanged', 'Choose a different bed.');
                }
                $bed = $this->availableBed($data, $time);
                if ($current !== null) {
                    $this->release($current, $actor, $time, $data['reason']);
                }
                $allocation = new BedAllocation;
                $allocation->forceFill(['admission_id' => $admission->id, 'bed_id' => $bed->id, 'assigned_by' => $actor->id,
                    'assigned_at' => $time, 'assignment_reason' => $data['reason']])->save();
            } elseif ($action === 'release') {
                if ($current === null) {
                    throw new WriteConflict('allocation_missing', 'There is no current bed to release.');
                }
                $this->release($current, $actor, $time, $data['reason']);
            } elseif ($action === 'authorize-discharge') {
                if ($admission->status !== 'admitted') {
                    throw new WriteConflict('admission_transition', 'This admission already has discharge authorization.');
                }
                if ($assignment === null || $assignment->id !== (int) $data['assignment_id']
                    || $assignment->version !== (int) $data['assignment_version'] || $assignment->doctor_id !== $actor->id) {
                    throw new WriteConflict('assignment_conflict', 'The current clinician assignment has changed. Reload this visit.');
                }
                $admission->forceFill(['status' => 'discharge_authorized', 'authorized_at' => $time,
                    'authorized_by' => $actor->id, 'discharge_reason' => $data['reason'],
                    'discharge_summary' => $data['discharge_summary'] ?? null, 'version' => $admission->version + 1])->save();
            } else {
                if ($admission->status !== 'discharge_authorized') {
                    throw new WriteConflict('discharge_not_authorized', 'The current doctor must authorize discharge first.');
                }
                if ($current !== null) {
                    $this->release($current, $actor, $time, $data['reason']);
                }
                $admission->forceFill(['status' => 'discharged', 'discharged_by' => $actor->id,
                    'discharged_at' => $time, 'version' => $admission->version + 1])->save();
            }
        }
        $workflowVersion = (int) $admission->revisions()->max('workflow_version') + 1;
        $revision = new AdmissionRevision;
        $revision->forceFill(['admission_id' => $admission->id, 'workflow_version' => $workflowVersion,
            'operation' => self::ACTIONS[$action][1], 'actor_id' => $actor->id, 'reason' => $data['reason'],
            'snapshot' => self::snapshot($admission, $workflowVersion), 'recorded_at' => $time])->save();
        $this->audit->record('admission.'.self::ACTIONS[$action][1], $actor->id, 'admission', $admission->id,
            ['version' => $workflowVersion, 'record_id' => $revision->id]);

        return $admission->fresh();
    }

    /** @param array<string, mixed> $data */
    private function availableBed(array $data, CarbonInterface $time): Bed
    {
        $bed = Bed::query()->findOrFail($data['bed_id']);
        $ward = Ward::query()->lockForUpdate()->findOrFail($bed->ward_id);
        $bed = Bed::query()->lockForUpdate()->findOrFail($bed->id);
        $this->version($bed->version, $data['bed_version']);
        $this->version($ward->version, $data['ward_version']);
        if (! $bed->is_active || ! $ward->is_active || $bed->allocations()->current()->lockForUpdate()->exists()) {
            throw new WriteConflict('bed_unavailable', 'This bed is occupied or inactive. Reload bed availability.');
        }
        $last = $bed->allocations()->reorder()->orderByDesc('released_at')->lockForUpdate()->first();
        $this->clock($time, $last?->released_at);

        return $bed;
    }

    private function release(BedAllocation $allocation, User $actor, CarbonInterface $time, string $reason): void
    {
        $allocation->forceFill(['released_at' => $time, 'released_by' => $actor->id,
            'release_reason' => $reason, 'version' => $allocation->version + 1])->save();
    }

    /** @param array<string, mixed> $data */
    private function location(Request $request, User $actor, array $data): Ward|Bed
    {
        $bed = self::resourceType($request) === 'bed';
        $create = self::action($request) === 'store';
        $record = $create ? ($bed ? new Bed : new Ward) : $request->attributes->get('location_record');
        if (! $create) {
            $this->version($record->version, $data['version']);
        }
        if ($bed) {
            $ward = Ward::query()->lockForUpdate()->findOrFail($create ? $data['ward_id'] : $record->ward_id);
            if ($create) {
                $this->version($ward->version, $data['ward_version']);
                if (! $ward->is_active) {
                    throw new WriteConflict('ward_inactive', 'Restore this ward before adding beds.');
                }
                $record->ward_id = $ward->id;
            }
            $label = $data['label'] ?? $record->label;
            if (Bed::query()->where('ward_id', $ward->id)->where('label', $label)->when(! $create,
                fn (Builder $query): Builder => $query->whereKeyNot($record->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['label' => ['This ward already has a bed with this label.']]);
            }
            if (! $create && $label !== $record->label && $record->allocations()->lockForUpdate()->exists()) {
                throw new WriteConflict('bed_identity_used', 'A used bed label cannot be changed.');
            }
        } elseif ($create && Ward::query()->where('code', $data['code'])->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['code' => ['This ward code is already in use.']]);
        }
        if (! $create && array_key_exists('is_active', $data) && ! $data['is_active']) {
            $occupied = $bed ? $record->allocations()->current()->lockForUpdate()->exists()
                : BedAllocation::query()->current()->whereHas('bed', fn (Builder $query): Builder => $query->where('ward_id', $record->id))->lockForUpdate()->exists();
            if ($occupied) {
                throw new WriteConflict('location_occupied', 'Transfer or release all occupants before retiring this location.');
            }
        }
        $record->forceFill(array_intersect_key($data, array_flip($bed ? ['label', 'daily_rate', 'is_active'] : ['code', 'name', 'type', 'daily_rate', 'is_active'])));
        if (! $create) {
            $record->version++;
        }
        $record->save();
        $type = $bed ? 'bed' : 'ward';
        $this->audit->record('location.'.($create ? 'created' : 'updated'), $actor->id, $type, $record->id);

        return $record->fresh();
    }

    private function version(int $current, mixed $expected): void
    {
        if ($current !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This record has changed. Reload it before trying again.');
        }
    }

    private function clock(CarbonInterface $time, ?CarbonInterface ...$events): void
    {
        foreach ($events as $event) {
            if ($event !== null && $time->lessThan($event)) {
                throw new WriteConflict('admission_clock_conflict', 'The server time precedes a saved event. Check the server clock.');
            }
        }
    }

    public static function response(Model $record): JsonResponse
    {
        return match (true) {
            $record instanceof Admission => (new AdmissionResource($record))->response(),
            $record instanceof Bed => (new BedResource($record))->response(),
            default => (new WardResource($record))->response(),
        };
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        if (self::isLocation($request)) {
            $class = self::resourceType($request) === 'bed' ? Bed::class : Ward::class;
            $record = $class::query()->lockForUpdate()->findOrFail($receipt->resource_id);
            if ($record->version !== (int) $receipt->resource_version) {
                throw new WriteConflict('idempotency_outdated', 'The original save completed, but this location changed. Reload it.');
            }

            return self::response($record);
        }
        $record = Admission::query()->visibleTo($actor)->lockForUpdate()->findOrFail($receipt->resource_id);
        $revision = $record->revisions()->where('workflow_version', $receipt->resource_version)->lockForUpdate()->first();
        if ($revision === null || self::snapshot($record) != $revision->snapshot) {
            throw new WriteConflict('idempotency_outdated', 'The original save completed, but this admission or visit changed. Reload it.');
        }

        return self::response($record);
    }

    /** @return array<string, mixed> */
    public static function snapshot(Admission $admission, ?int $workflowVersion = null): array
    {
        $visit = $admission->encounter()->with('currentAssignment')->firstOrFail();
        $current = $admission->allocations()->current()->first();

        return $admission->only(['id', 'encounter_id', 'patient_id', 'status', 'version', 'admitted_by',
            'authorized_by', 'discharged_by', 'discharge_reason', 'discharge_summary']) + [
            'workflow_version' => $workflowVersion ?? (int) $admission->revisions()->max('workflow_version'),
            'encounter_version' => $visit->version, 'encounter_status' => $visit->status,
            'current_assignment' => $visit->currentAssignment === null ? null : EncounterFlow::assignment($visit->currentAssignment),
            'admitted_at' => $admission->admitted_at->toISOString(), 'authorized_at' => $admission->authorized_at?->toISOString(),
            'discharged_at' => $admission->discharged_at?->toISOString(),
            'current_allocation' => $current === null ? null : self::allocation($current),
        ];
    }

    /** @return array<string, mixed> */
    public static function allocation(BedAllocation $allocation): array
    {
        return $allocation->only(['id', 'admission_id', 'bed_id', 'assigned_by', 'assignment_reason', 'released_by', 'release_reason', 'version']) + [
            'assigned_at' => $allocation->assigned_at->toISOString(), 'released_at' => $allocation->released_at?->toISOString(),
        ];
    }
}
