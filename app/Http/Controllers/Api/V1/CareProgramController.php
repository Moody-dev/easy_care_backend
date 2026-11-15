<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CareProgramCommandRequest;
use App\Http\Requests\Api\V1\CareRecordCommandRequest;
use App\Http\Requests\Api\V1\ListCareProgramsRequest;
use App\Http\Resources\BirthResource;
use App\Http\Resources\CareProgramResource;
use App\Http\Resources\ClinicalResource;
use App\Http\Resources\ClinicalRevisionResource;
use App\Models\CareProgram;
use App\Models\ClinicalRecord;
use App\Models\ClinicalTemplateVersion;
use App\Models\Encounter;
use App\Services\AuditTrail;
use App\Services\CarePrograms;
use App\Services\ClinicalPayloads;
use App\Services\ClinicalRecords;
use App\Services\IdempotentCommands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CareProgramController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['types' => CareProgram::TYPES, 'timezone' => config('appointments.timezone'),
            'published_forms_required_for_signing' => true, 'separate_birth_records' => true]]);
    }
    public function index(ListCareProgramsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = CareProgram::query()->visibleTo($request->user('web'));
        foreach (['type','status','patient_id'] as $field) {
            if ($request->filled($field)) { $query->where($field, $request->validated($field)); }
        }
        $audit->record('care.listed', $request->user('web')->id, 'care_program');
        return CareProgramResource::collection($query->orderByDesc('started_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }
    public function show(Request $request, int $careProgram, AuditTrail $audit): CareProgramResource
    {
        $program = CareProgram::query()->visibleTo($request->user('web'))->findOrFail($careProgram);
        $audit->record('care.viewed', $request->user('web')->id, 'care_program', $program->id);
        return new CareProgramResource($program);
    }
    public function births(ListCareProgramsRequest $request, int $careProgram, AuditTrail $audit): AnonymousResourceCollection
    {
        $program = CareProgram::query()->visibleTo($request->user('web'))->findOrFail($careProgram);
        $audit->record('care.viewed', $request->user('web')->id, 'care_program', $program->id);
        return BirthResource::collection($program->births()->with('patientLink')->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }
    public function write(Request $request, CarePrograms $care, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $care): JsonResponse {
            $result = $care->execute($request, $request->user('web'), app(CareProgramCommandRequest::class)->validated());
            return CarePrograms::response($result)->setStatusCode(in_array(CarePrograms::action($request), ['store','birth'], true) ? 201 : 200);
        });
    }
    public function records(ListCareProgramsRequest $request, int $careProgram, AuditTrail $audit): AnonymousResourceCollection
    {
        CareProgram::query()->visibleTo($request->user('web'))->findOrFail($careProgram);
        $query = ClinicalRecord::query()->careVisibleTo($request->user('web'))
            ->whereHas('careLink', function (Builder $links) use ($careProgram, $request): void {
                $links->where('care_program_id', $careProgram);
                if ($request->filled('birth_id')) { $links->where('birth_id', $request->integer('birth_id')); }
            })->whereDoesntHave('amendment', fn (Builder $amendments): Builder => $amendments->finalized())->with(ClinicalRecords::LOAD);
        $audit->record('care.viewed', $request->user('web')->id, 'care_program', $careProgram);
        return ClinicalResource::collection($query->orderByDesc('recorded_at')->orderByDesc('id')->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }
    public function record(Request $request, int $careProgram, int $encounter, int $clinicalRecord, AuditTrail $audit): ClinicalResource
    {
        $record = ClinicalRecords::visibleFor($request, $request->user('web'))->where('encounter_id', $encounter)
            ->with(ClinicalRecords::LOAD)->findOrFail($clinicalRecord);
        $audit->record('care.viewed', $request->user('web')->id, 'care_program', $careProgram);
        return new ClinicalResource($record);
    }
    public function history(ListCareProgramsRequest $request, int $careProgram, int $encounter, int $clinicalRecord, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = ClinicalRecords::visibleFor($request, $request->user('web'))->where('encounter_id', $encounter)->findOrFail($clinicalRecord);
        $audit->record('care.viewed', $request->user('web')->id, 'care_program', $careProgram);
        return ClinicalRevisionResource::collection($record->revisions()->with('actor:id,name')->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }
    public function clinicalWrite(Request $request, ClinicalRecords $clinical, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $clinical): JsonResponse {
            $record = $clinical->execute($request, $request->user('web'), app(CareRecordCommandRequest::class)->validated());
            return (new ClinicalResource($record))->response()->setStatusCode(in_array(ClinicalRecords::action($request), ['store','amend'], true) ? 201 : 200);
        });
    }
    public function forms(ListCareProgramsRequest $request, ClinicalPayloads $payloads): JsonResponse
    {
        $type = $request->validated('type');
        $query = ClinicalTemplateVersion::query()->with('template')->whereHas('template', fn (Builder $templates): Builder => $templates
            ->where('is_active', true)->where('code', 'like', $type === null ? 'care.%' : 'care.'.$type.'.%'))
            ->whereNotExists(fn (\Illuminate\Database\Query\Builder $later): \Illuminate\Database\Query\Builder => $later->selectRaw('1')->from('clinical_template_versions as later')
                ->whereColumn('later.clinical_template_id','clinical_template_versions.clinical_template_id')
                ->whereColumn('later.version','>','clinical_template_versions.version'));
        $page = $query->orderBy('clinical_template_id')->paginate($request->integer('per_page', config('api.pagination.per_page')));
        return response()->json(['data' => $page->map(fn (ClinicalTemplateVersion $v): array => [
            'id' => $v->id, 'template_id' => $v->clinical_template_id, 'code' => $v->template->code,
            'version' => $v->version, 'title' => $v->title, 'fields' => (object) $payloads->definition($v), 'supported' => true]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }
}
