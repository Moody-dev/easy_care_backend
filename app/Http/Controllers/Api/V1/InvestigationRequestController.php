<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InvestigationCommandRequest;
use App\Http\Requests\Api\V1\ListInvestigationRequestsRequest;
use App\Http\Resources\InvestigationItemResource;
use App\Http\Resources\InvestigationOrderResource;
use App\Http\Resources\InvestigationRevisionResource;
use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\InvestigationRequests;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvestigationRequestController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => ['timezone' => 'Africa/Lagos', 'priorities' => ['routine', 'urgent'],
            'statuses' => InvestigationItem::STATUSES, 'laboratory_requires_collection' => true,
            'cancellation_before_processing_only' => true, 'closed_visits_read_only' => true, 'result_workflow_available' => false]]);
    }

    public function index(ListInvestigationRequestsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = InvestigationOrder::query()->visibleTo($request->user('web'))->with(InvestigationRequests::LOAD)->whereHas('items');
        foreach (['patient_id', 'encounter_id', 'ordered_by'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        if ($request->hasAny(['department', 'status', 'priority', 'active'])) {
            $query->whereHas('items', fn (Builder $items): Builder => $this->filterItems($items, $request));
        }
        $audit->record('investigation.orders_listed', $request->user('web')->id, 'investigation_order');

        return InvestigationOrderResource::collection($query->orderByDesc('requested_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function queue(ListInvestigationRequestsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = InvestigationItem::query()->visibleTo($request->user('web'))->with(['schema', 'order.patient']);
        $this->filterItems($query, $request, true);
        foreach (['patient_id', 'ordered_by'] as $field) {
            if ($request->has($field)) {
                $query->whereHas('order', fn (Builder $orders): Builder => $orders->where($field, $request->integer($field)));
            }
        }
        foreach (['encounter_id', 'order_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        $audit->record('investigation.queue_viewed', $request->user('web')->id, 'investigation_order');

        return InvestigationItemResource::collection($query->orderByRaw("CASE WHEN priority = 'urgent' THEN 0 ELSE 1 END")
            ->orderBy('id')->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function show(Request $request, int $investigationOrder, AuditTrail $audit): InvestigationOrderResource
    {
        $order = InvestigationOrder::query()->visibleTo($request->user('web'))->with(InvestigationRequests::LOAD)->findOrFail($investigationOrder);
        $audit->record('investigation.order_viewed', $request->user('web')->id, 'investigation_order', $order->id);

        return new InvestigationOrderResource($order);
    }

    public function history(ListInvestigationRequestsRequest $request, int $investigationOrder, AuditTrail $audit): AnonymousResourceCollection
    {
        $order = InvestigationOrder::query()->visibleTo($request->user('web'))->findOrFail($investigationOrder);
        $audit->record('investigation.history_viewed', $request->user('web')->id, 'investigation_order', $order->id);

        return InvestigationRevisionResource::collection($order->revisions()->reorder()->orderByDesc('version')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function item(Request $request, int $investigationOrder, int $investigationItem, AuditTrail $audit): InvestigationItemResource
    {
        $order = InvestigationOrder::query()->visibleTo($request->user('web'))->findOrFail($investigationOrder);
        $item = $order->items()->with(['schema', 'order.patient'])->findOrFail($investigationItem);
        $audit->record('investigation.order_viewed', $request->user('web')->id, 'investigation_order', $order->id);

        return new InvestigationItemResource($item);
    }

    public function write(Request $request, IdempotentCommands $commands, InvestigationRequests $flow): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow): JsonResponse {
            $order = $flow->execute($request, $request->user('web'), app(InvestigationCommandRequest::class)->validated());

            return InvestigationRequests::response($order)->setStatusCode(InvestigationRequests::action($request) === 'store' ? 201 : 200);
        });
    }

    private function filterItems(Builder $query, Request $request, bool $queue = false): Builder
    {
        foreach (['department', 'status', 'priority'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->has('active')) {
            $request->boolean('active') ? $query->where('status', '<>', 'cancelled') : $query->where('status', 'cancelled');
        } elseif ($queue && ! $request->has('status')) {
            $query->where('status', '<>', 'cancelled');
        }

        return $query;
    }
}
