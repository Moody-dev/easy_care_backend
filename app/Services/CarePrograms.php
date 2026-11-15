<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\BirthResource;
use App\Http\Resources\CareProgramResource;
use App\Models\Birth;
use App\Models\BirthPatientLink;
use App\Models\CareProgram;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;

class CarePrograms
{
    public function __construct(private AuditTrail $audit) {}

    public static function isCommand(string $route): bool
    {
        return in_array($route, ['api.v1.care-programs.store','api.v1.care-programs.complete',
            'api.v1.care-programs.birth','api.v1.care-programs.link'], true);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        foreach (['care.view','patients.view','encounters.view', self::action($request) === 'link' ? 'care.link_birth' : 'care.manage'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        abort_unless($actor->is_active, 403);
        $program = self::action($request) === 'store' ? null
            : CareProgram::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('careProgram'));
        $visitId = $request->route('encounter') ?? $request->input('encounter_id');
        if ((! is_int($visitId) && ! is_string($visitId)) || preg_match('/^[1-9][0-9]{0,17}$/D', (string) $visitId) !== 1) {
            throw ValidationException::withMessages(['encounter_id' => ['Choose a saved encounter ID.']]);
        }
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visitId);
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visit->patient_id);
        abort_if($program !== null && $program->patient_id !== $visit->patient_id, 404);
        if (self::action($request) === 'link') {
            $birth = Birth::query()->where('delivery_id', $program->id)->lockForUpdate()->findOrFail($request->route('birth'));
            $request->attributes->set('care_birth', $birth);
        }
        $request->attributes->set('care_program', $program);
        $request->attributes->set('care_encounter', $visit);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): CareProgram|Birth
    {
        $visit = $request->attributes->get('care_encounter');
        $this->version($visit->version, $data['encounter_version']);
        if (! in_array($visit->status, ['open','in_progress'], true)) {
            throw new WriteConflict('care_encounter_closed', 'Closed visits are read-only.');
        }
        $program = $request->attributes->get('care_program');
        $time = now('UTC');
        if ($time->lessThan($visit->opened_at) || ($program !== null && $time->lessThan($program->started_at))) {
            throw new WriteConflict('care_clock_conflict', 'The server time precedes a saved event.');
        }
        $action = self::action($request);
        if ($action === 'store') {
            $this->version($visit->patient->version, $data['patient_version']);
            $program = new CareProgram;
            $program->forceFill(['patient_id' => $visit->patient_id, 'encounter_id' => $visit->id, 'type' => $data['type'],
                'started_by' => $actor->id, 'started_at' => $time, 'start_reason' => $data['reason']])->save();
            $result = $program->fresh();
        } else {
            $this->version($program->version, $data['program_version']);
            if ($program->status !== 'active') {
                throw new WriteConflict('care_program_completed', 'This care program is completed and read-only.');
            }
            if ($action === 'complete') {
                foreach ($program->births()->with('patientLink')->lockForUpdate()->get() as $birth) {
                    if ($time->lessThan($birth->recorded_at) || ($birth->patientLink !== null && $time->lessThan($birth->patientLink->verified_at))) {
                        throw new WriteConflict('care_clock_conflict', 'The server time precedes a saved baby event.');
                    }
                }
                $latest = ClinicalRecord::query()->whereHas('careLink', fn (Builder $links): Builder => $links->where('care_program_id', $program->id))
                    ->orderByDesc('recorded_at')->lockForUpdate()->first();
                if ($latest !== null && ($time->lessThan($latest->recorded_at) || ($latest->finalized_at !== null && $time->lessThan($latest->finalized_at)))) {
                    throw new WriteConflict('care_clock_conflict', 'The server time precedes a saved program record.');
                }
                if (ClinicalRecord::query()->whereHas('careLink', fn (Builder $links): Builder => $links->where('care_program_id', $program->id))->where('status','draft')->lockForUpdate()->exists()) {
                    throw new WriteConflict('care_pending_drafts', 'Sign or void pending drafts before completing this program.');
                }
                $program->forceFill(['status' => 'completed', 'version' => $program->version + 1, 'completed_by' => $actor->id,
                    'completed_encounter_id' => $visit->id,
                    'completed_at' => $time, 'completion_reason' => $data['reason']])->save();
                $result = $program->fresh();
            } elseif ($action === 'birth') {
                abort_unless($program->type === 'delivery', 422);
                if ($program->births()->where('birth_order', $data['birth_order'])->lockForUpdate()->exists()) {
                    throw new WriteConflict('birth_order_exists', 'This delivery already has a baby with this birth order.');
                }
                $birth = new Birth;
                $birth->forceFill(['delivery_id' => $program->id, 'birth_order' => $data['birth_order'],
                    'encounter_id' => $visit->id,
                    'recorded_by' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason']])->save();
                $result = $birth->fresh();
            } else {
                $birth = $request->attributes->get('care_birth');
                $child = Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($data['child_patient_id']);
                $this->version($child->version, $data['child_patient_version']);
                if ($child->id === $program->patient_id) {
                    throw ValidationException::withMessages(['child_patient_id' => ['The baby must have a different patient identity from the mother.']]);
                }
                if ($birth->patientLink()->lockForUpdate()->exists() || BirthPatientLink::query()->where('child_patient_id', $child->id)->lockForUpdate()->exists()) {
                    throw new WriteConflict('birth_identity_linked', 'This baby or registered child is already linked. Review its saved identity.');
                }
                if ($time->lessThan($birth->recorded_at)) {
                    throw new WriteConflict('care_clock_conflict', 'The server time precedes the baby record.');
                }
                $link = new BirthPatientLink;
                $link->forceFill(['birth_id' => $birth->id, 'child_patient_id' => $child->id,
                    'encounter_id' => $visit->id,
                    'verified_by' => $actor->id, 'verified_at' => $time, 'reason' => $data['reason']])->save();
                $result = $birth->fresh();
            }
        }
        $this->audit->record('care.'.match ($action) { 'store' => 'opened', 'complete' => 'completed', 'birth' => 'birth_recorded', 'link' => 'birth_linked' },
            $actor->id, 'care_program', $program->id);
        return $result;
    }

    public static function response(CareProgram|Birth $record): JsonResponse
    {
        return $record instanceof Birth ? (new BirthResource($record))->response() : (new CareProgramResource($record))->response();
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        $birth = in_array(self::action($request), ['birth','link'], true);
        $record = $birth ? Birth::query()->where('delivery_id', $request->route('careProgram'))->findOrFail($receipt->resource_id)
            : CareProgram::query()->visibleTo($actor)->findOrFail($receipt->resource_id);
        $version = $birth ? ($record->patientLink()->exists() ? 2 : 1) : $record->version;
        if ($version !== (int) $receipt->resource_version) {
            throw new WriteConflict('idempotency_outdated', 'The original save completed, but the saved outcome changed. Reload it.');
        }
        if ($birth && $record->patientLink !== null) {
            Patient::query()->visibleTo($actor)->findOrFail($record->patientLink->child_patient_id);
        }
        return self::response($record);
    }

    public static function validateForm(Request $request, array $payload, bool $sign, ?ClinicalRecord $original): void
    {
        $program = $request->attributes->get('care_program');
        $birthId = $original?->careLink?->birth_id ?? $request->input('birth_id');
        if ($birthId !== null) {
            Birth::query()->where('delivery_id', $program->id)->findOrFail($birthId);
        }
        $id = $payload['clinical_template_version_id'] ?? null;
        if ($id === null) {
            if ($sign) {
                throw ValidationException::withMessages(['payload.clinical_template_version_id' => ['Choose a published hospital-approved care form before signing.']]);
            }
            return;
        }
        $version = ClinicalTemplateVersion::query()->with('template')->findOrFail($id);
        if (($original?->entry?->clinical_template_version_id === null && ! $version->template->is_active)
            || $version->published_at->greaterThan(now('UTC'))) {
            throw ValidationException::withMessages(['payload.clinical_template_version_id' => ['Choose an available published care form.']]);
        }
        $prefix = 'care.'.($birthId === null ? $program->type : 'birth').'.';
        if (! str_starts_with($version->template->code, $prefix)) {
            throw ValidationException::withMessages(['payload.clinical_template_version_id' => ['Choose a published form for this care program and subject.']]);
        }
    }

    private function version(int $actual, mixed $expected): void
    {
        if ($actual !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This record changed. Reload it before saving.');
        }
    }
}
