<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdmissionCommandRequest;
use App\Http\Requests\Api\V1\ListAdmissionsRequest;
use App\Http\Resources\AdmissionResource;
use App\Http\Resources\AdmissionRevisionResource;
use App\Http\Resources\BedAllocationResource;
use App\Models\Admission;
use App\Services\AdmissionFlow;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdmissionController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['timezone' => config('appointments.timezone'),
            'statuses' => array_keys(Admission::TRANSITIONS), 'active_admissions_per_patient' => 1,
            'bed_required_at_admission' => false, 'timestamps' => 'server_utc',
            'discharge_authorizer' => 'current_doctor', 'discharge_completer' => 'different_nurse',
            'identity_verification_required' => true]]);
    }

    public function index(ListAdmissionsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = Admission::query()->visibleTo($request->user('web'));
        foreach (['status', 'patient_id', 'encounter_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->validated($field));
            }
        }
        if ($request->filled('ward_id')) {
            $query->whereHas('currentOccupancy.bed', fn (Builder $beds): Builder => $beds->where('ward_id', $request->integer('ward_id')));
        }
        $audit->record('admission.listed', $request->user('web')->id, 'admission');

        return AdmissionResource::collection($query->orderBy('admitted_at')->orderBy('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()))
            ->additional(['timezone' => config('appointments.timezone')]);
    }

    public function show(Request $request, int $admission, AuditTrail $audit): AdmissionResource
    {
        $record = Admission::query()->visibleTo($request->user('web'))->findOrFail($admission);
        $audit->record('admission.viewed', $request->user('web')->id, 'admission', $record->id);

        return new AdmissionResource($record);
    }

    public function history(ListAdmissionsRequest $request, int $admission, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = Admission::query()->visibleTo($request->user('web'))->findOrFail($admission);
        $audit->record('admission.history_viewed', $request->user('web')->id, 'admission', $record->id);

        return AdmissionRevisionResource::collection($record->revisions()->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function allocations(ListAdmissionsRequest $request, int $admission, AuditTrail $audit): AnonymousResourceCollection
    {
        $record = Admission::query()->visibleTo($request->user('web'))->findOrFail($admission);
        $audit->record('admission.history_viewed', $request->user('web')->id, 'admission', $record->id);

        return BedAllocationResource::collection($record->allocations()->paginate($request->integer('per_page', config('api.pagination.per_page'))));
    }

    public function write(Request $request, AdmissionFlow $flow, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow): JsonResponse {
            $record = $flow->execute($request, $request->user('web'), app(AdmissionCommandRequest::class)->validated());

            return AdmissionFlow::response($record)->setStatusCode(AdmissionFlow::action($request) === 'store' ? 201 : 200);
        });
    }
}
