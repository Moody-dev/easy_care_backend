<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListLocationsRequest;
use App\Http\Requests\Api\V1\LocationCommandRequest;
use App\Http\Resources\BedResource;
use App\Http\Resources\WardResource;
use App\Models\Bed;
use App\Models\Ward;
use App\Services\AdmissionFlow;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LocationController extends Controller
{
    public function wards(ListLocationsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = Ward::query();
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        $audit->record('location.listed', $request->user('web')->id, 'ward');

        return WardResource::collection($query->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function beds(ListLocationsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = $this->bedQuery();
        if ($request->has('ward_id')) {
            $query->where('ward_id', $request->integer('ward_id'));
        }
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($request->has('available')) {
            if ($request->boolean('available')) {
                $query->available();
            } else {
                $query->where(fn (Builder $beds): Builder => $beds->where('is_active', false)
                    ->orWhereHas('ward', fn (Builder $wards): Builder => $wards->where('is_active', false))->orWhereHas('currentOccupancy'));
            }
        }
        $audit->record('location.listed', $request->user('web')->id, 'bed');

        return BedResource::collection($query->orderBy('ward_id')->orderBy('label')->orderBy('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function show(Request $request, int $location, AuditTrail $audit): WardResource|BedResource
    {
        $bed = str_contains($request->route()->getName(), '.beds.');
        $record = ($bed ? $this->bedQuery() : Ward::query())->findOrFail($location);
        $audit->record('location.viewed', $request->user('web')->id, $bed ? 'bed' : 'ward', $record->id);

        return $bed ? new BedResource($record) : new WardResource($record);
    }

    private function bedQuery(): Builder
    {
        return Bed::query()->withExists(['currentOccupancy as occupied'])
            ->addSelect(['ward_active' => Ward::query()->select('is_active')->whereColumn('wards.id', 'beds.ward_id')]);
    }

    public function write(Request $request, AdmissionFlow $flow, IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow): JsonResponse {
            $record = $flow->execute($request, $request->user('web'), app(LocationCommandRequest::class)->validated());

            return AdmissionFlow::response($record)->setStatusCode(AdmissionFlow::action($request) === 'store' ? 201 : 200);
        });
    }
}
