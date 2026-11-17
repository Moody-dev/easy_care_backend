<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\MedicationAuthorization;
use App\Services\MedicationFulfillment;
use App\Services\IdempotentCommands;
use App\Services\AuditTrail;
use App\Http\Requests\Api\V1\MedicationCommandRequest;
use App\Support\FixedPoint;
use Illuminate\Database\Eloquent\Builder;

class MedicationController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['timezone' => 'Africa/Lagos', 'doctor_catalog_authorization_required' => true,
            'independent_return_approval_required' => true, 'quantity_unit' => 'drug_base_unit', 'returns_reset_allowance' => false,
            'damaged_and_expired_returns_available' => false, 'administration_deducts_stock' => false, 'closed_visits_read_only' => true]]);
    }

    public function index(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate(['encounter_id' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = MedicationAuthorization::query()->visibleTo($request->user('web'));
        if (isset($data['encounter_id'])) {
            $query->whereHas('item.prescription.record', fn (Builder $query): Builder => $query->where('encounter_id', $data['encounter_id']));
        }
        $page = $query->orderByDesc('id')->paginate($data['per_page'] ?? config('api.pagination.per_page'));
        $page->through(fn (MedicationAuthorization $authorization): array => ['id' => $authorization->id,
            'prescription_item_id' => $authorization->prescription_item_id, 'drug_id' => $authorization->drug_id,
            'maximum_quantity' => FixedPoint::format($authorization->maximum_quantity_micros), 'version' => (int) $authorization->events()->max('version')]);
        $audit->record('medication.listed', $request->user('web')->id, 'medication');

        return response()->json($page);
    }

    public function show(Request $request, MedicationFulfillment $service, AuditTrail $audit): JsonResponse
    {
        return DB::transaction(function () use ($request, $service, $audit): JsonResponse {
            $authorization = MedicationAuthorization::query()->visibleTo($request->user('web'))->lockForUpdate()->findOrFail($request->route('medication'));
            $audit->record('medication.viewed', $request->user('web')->id, 'medication', $authorization->id);

            return $service->response($authorization);
        });
    }

    public function records(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $authorization = MedicationAuthorization::query()->visibleTo($request->user('web'))->findOrFail($request->route('medication'));
        $kind = $request->route('kind');
        if ($kind === 'history') {
            $query = DB::table('medication_events')->where('authorization_id', $authorization->id);
        } elseif ($kind === 'dispenses') {
            $query = DB::table('medication_dispenses')->where('authorization_id', $authorization->id);
        } elseif ($kind === 'returns') {
            $query = DB::table('medication_returns as r')->join('medication_dispenses as d', 'd.id', '=', 'r.dispense_id')
                ->leftJoin('medication_return_reviews as v', 'v.return_id', '=', 'r.id')->where('d.authorization_id', $authorization->id)
                ->select('r.*', 'v.decision', 'v.actor_id as reviewed_by', 'v.recorded_at as reviewed_at', 'v.reason as review_reason', 'v.movement_id');
        } else {
            $query = DB::table('medication_administrations as a')->join('medication_dispenses as d', 'd.id', '=', 'a.dispense_id')
                ->where('d.authorization_id', $authorization->id)->select('a.*')
                ->selectRaw('EXISTS (SELECT 1 FROM medication_administrations c WHERE c.corrects_id = a.id) as superseded');
        }
        $id = match ($kind) { 'returns' => 'r.id', 'administrations' => 'a.id', default => 'id' };
        $page = $query->orderByDesc($id)->paginate($data['per_page'] ?? config('api.pagination.per_page'));
        $page->through(function (\stdClass $row): array {
            $result = (array) $row;
            if (isset($result['quantity_micros'])) {
                $result['quantity'] = FixedPoint::format((int) $result['quantity_micros']);
                unset($result['quantity_micros']);
            }

            return $result;
        });
        $audit->record($kind === 'history' ? 'medication.history_viewed' : 'medication.viewed', $request->user('web')->id, 'medication', $authorization->id);

        return response()->json($page);
    }

    public function write(Request $request, MedicationFulfillment $service, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $service): JsonResponse {
            $authorization = $service->execute($request, $request->user('web'), app(MedicationCommandRequest::class)->validated());

            return $service->response($authorization)->setStatusCode(201);
        });
    }
}
