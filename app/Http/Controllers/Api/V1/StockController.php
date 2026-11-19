<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListStockRequest;
use App\Http\Requests\Api\V1\StockCatalogCommandRequest;
use App\Http\Requests\Api\V1\StockOperationCommandRequest;
use App\Http\Resources\StockCatalogResource;
use App\Http\Resources\StockOperationResource;
use App\Models\StockCatalogRevision;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\StockStore;
use App\Models\StockStoreAccess;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\MedicationPricing;
use App\Services\StockCatalog;
use App\Services\StockOperations;
use App\Support\FixedPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class StockController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => ['kinds' => StockOperation::KINDS, 'timezone' => 'Africa/Lagos',
            'quantity_decimal_places' => 6, 'money_decimal_places' => 6, 'independent_approval_required' => true,
            'requisitions_post_as_one_document' => true, 'valuation_configured' => false]]);
    }

    public function catalogIndex(ListStockRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $catalog = $request->route('catalog');
        $query = StockCatalog::query($catalog);
        if ($request->has('active') && in_array($catalog, ['drugs','packs','suppliers','stores'], true)) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($request->has('drug_id') && in_array($catalog, ['packs','prices','batches'], true)) {
            $query->where('drug_id', $request->integer('drug_id'));
        }
        if ($request->has('q') && $catalog !== 'prices') {
            $column = $catalog === 'batches' ? 'batch_number' : 'name';
            $query->whereLike($column, '%'.$request->string('q')->toString().'%');
        }
        if ($request->has('code') && in_array($catalog, ['units','drugs','packs','suppliers','stores'], true)) {
            $query->where('code', $request->string('code')->toString());
        }
        if ($catalog === 'prices') {
            foreach (['currency','kind'] as $column) {
                if ($request->has($column)) {
                    $query->where($column, $request->string($column)->toString());
                }
            }
        }
        $audit->record('stock.listed', $request->user('web')->id, 'stock_catalog');

        $page = $query->orderByDesc('id')->paginate($this->limit($request))->appends($request->validated());
        if ($catalog === 'drugs') {
            app(MedicationPricing::class)->attach($page->getCollection());
        }

        return StockCatalogResource::collection($page);
    }

    public function catalogShow(Request $request, AuditTrail $audit): StockCatalogResource
    {
        $record = StockCatalog::query($request->route('catalog'))->findOrFail($request->route('stockRecord'));
        $audit->record('stock.viewed', $request->user('web')->id, 'stock_catalog', $record->id);

        if ($request->route('catalog') === 'drugs') {
            app(MedicationPricing::class)->attach(collect([$record]));
        }

        return new StockCatalogResource($record);
    }

    public function catalogHistory(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        Gate::authorize('stock.review');
        $catalog = $request->route('catalog');
        $record = StockCatalog::query($catalog)->findOrFail($request->route('stockRecord'));
        $audit->record('stock.history_viewed', $request->user('web')->id, 'stock_catalog', $record->id);

        return response()->json(StockCatalogRevision::query()->where('catalog', $catalog)->where('record_id', $record->id)
            ->orderByDesc('version')->paginate($this->limit($request))->appends($request->validated()));
    }

    public function accesses(ListStockRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = StockStoreAccess::query()->where('store_id', $request->route('stockStore'));
        StockStore::query()->findOrFail($request->route('stockStore'));
        $audit->record('stock.listed', $request->user('web')->id, 'stock_catalog');

        return StockCatalogResource::collection($query->orderBy('id')->paginate($this->limit($request))->appends($request->validated()));
    }

    public function myStores(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        $query = StockStore::query()->whereIn('id', $this->storeIds($request));
        $audit->record('stock.listed', $request->user('web')->id, 'stock_catalog');
        $page = $query->orderBy('id')->paginate($this->limit($request))->appends($request->validated());
        $page->through(fn (StockStore $store): array => StockCatalog::snapshot('stores', $store));

        return response()->json($page);
    }

    public function accessHistory(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        $access = StockStoreAccess::query()->where('store_id', $request->route('stockStore'))->where('user_id', $request->route('stockStaff'))->firstOrFail();
        $audit->record('stock.history_viewed', $request->user('web')->id, 'stock_catalog', $access->id);

        return response()->json(StockCatalogRevision::query()->where('catalog', 'store-access')->where('record_id', $access->id)
            ->orderByDesc('version')->paginate($this->limit($request))->appends($request->validated()));
    }

    public function index(ListStockRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = StockOperation::query()->visibleTo($request->user('web'));
        foreach (['kind','status'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->string($field)->toString());
            }
        }
        if ($request->has('store_id')) {
            $id = $request->integer('store_id');
            $query->where(fn (Builder $stores): Builder => $stores->where('source_store_id', $id)->orWhere('destination_store_id', $id));
        }
        $audit->record('stock.listed', $request->user('web')->id, 'stock_operation');

        return StockOperationResource::collection($query->with('lines.movements')->orderByDesc('id')
            ->paginate($this->limit($request))->appends($request->validated()));
    }

    public function show(Request $request, AuditTrail $audit): StockOperationResource
    {
        $operation = StockOperation::query()->visibleTo($request->user('web'))->with('lines.movements')->findOrFail($request->route('stockOperation'));
        $audit->record('stock.viewed', $request->user('web')->id, 'stock_operation', $operation->id);

        return new StockOperationResource($operation);
    }

    public function history(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        Gate::authorize('stock.review');
        $operation = StockOperation::query()->visibleTo($request->user('web'))->findOrFail($request->route('stockOperation'));
        $audit->record('stock.history_viewed', $request->user('web')->id, 'stock_operation', $operation->id);

        return response()->json($operation->revisions()->orderByDesc('version')->paginate($this->limit($request))->appends($request->validated()));
    }

    public function balances(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        $query = DB::table('stock_balances as p')->join('drug_batches as batch', 'batch.id', '=', 'p.batch_id')->join('drugs as drug', 'drug.id', '=', 'p.drug_id');
        $this->positionFilters($request, $query);
        $page = $query->select(['p.*','batch.batch_number','batch.expires_on','drug.name as drug_name','drug.base_unit_id'])
            ->orderBy('p.store_id')->orderBy('p.batch_id')->paginate($this->limit($request))->appends($request->validated());
        $page->through(fn ($row): array => ['store_id' => (int) $row->store_id, 'batch_id' => (int) $row->batch_id, 'drug_id' => (int) $row->drug_id,
            'drug_name' => $row->drug_name, 'base_unit_id' => (int) $row->base_unit_id, 'batch_number' => $row->batch_number, 'expires_on' => $row->expires_on,
            'quantity' => FixedPoint::format((int) $row->quantity_micros), 'version' => (int) $row->movement_count, 'last_movement_id' => (int) $row->last_movement_id]);
        $audit->record('stock.listed', $request->user('web')->id, 'stock_operation');

        return response()->json($page);
    }

    public function reconciliation(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        Gate::authorize('stock.review');
        $positions = DB::table('stock_balances')->select(['store_id','batch_id','drug_id'])
            ->union(DB::table('stock_position_heads as h')->join('drug_batches as b', 'b.id', '=', 'h.batch_id')->select(['h.store_id','h.batch_id','b.drug_id']));
        $query = DB::query()->fromSub($positions, 'p')
            ->leftJoin('stock_balances as b', fn ($join) => $join->on('b.store_id', '=', 'p.store_id')->on('b.batch_id', '=', 'p.batch_id'))
            ->leftJoin('stock_position_heads as h', fn ($join) => $join->on('h.store_id', '=', 'p.store_id')->on('h.batch_id', '=', 'p.batch_id'));
        $this->positionFilters($request, $query);
        $page = $query->select(['p.store_id','p.batch_id','p.drug_id','b.quantity_micros as ledger_quantity','b.movement_count as ledger_count',
            'b.last_movement_id','h.quantity_micros as head_quantity','h.movement_count as head_count','h.last_id'])
            ->orderBy('p.store_id')->orderBy('p.batch_id')->paginate($this->limit($request))->appends($request->validated());
        $page->through(fn ($row): array => ['store_id' => (int) $row->store_id, 'batch_id' => (int) $row->batch_id, 'drug_id' => (int) $row->drug_id,
            'ledger_quantity' => FixedPoint::format((int) ($row->ledger_quantity ?? 0)), 'counter_quantity' => FixedPoint::format((int) ($row->head_quantity ?? 0)),
            'version' => (int) ($row->ledger_count ?? 0), 'consistent' => $row->head_quantity !== null && (int) $row->ledger_quantity === (int) $row->head_quantity
                && (int) $row->ledger_count === (int) $row->head_count && (int) $row->last_movement_id === (int) $row->last_id]);
        $audit->record('stock.reconciled', $request->user('web')->id, 'stock_operation');

        return response()->json($page);
    }

    public function movements(ListStockRequest $request, AuditTrail $audit): JsonResponse
    {
        $query = StockMovement::query()->whereIn('store_id', $this->storeIds($request));
        foreach (['store_id','drug_id','batch_id'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        $through = $request->has('through_id') ? $request->integer('through_id') : (int) (clone $query)->max('id');
        $page = $query->where('id', '<=', $through)->orderByDesc('id')->paginate($this->limit($request))->appends($request->validated() + ['through_id' => $through]);
        $page->through(fn (StockMovement $row): array => $row->only(['id','store_id','batch_id','drug_id','pack_id','kind','currency','reference_type','reference_id','supplier_id','reversal_of','created_by','reason'])
            + ['quantity' => FixedPoint::format($row->quantity_micros), 'unit_cost' => $row->unit_cost_micros === null ? null : FixedPoint::format($row->unit_cost_micros),
                'recorded_at' => $row->recorded_at->toISOString()]);
        $audit->record('stock.listed', $request->user('web')->id, 'stock_operation');

        return response()->json($page->toArray() + ['through_id' => $through]);
    }

    public function write(Request $request, IdempotentCommands $commands, StockOperations $flow, StockCatalog $catalog): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow, $catalog): JsonResponse {
            $actor = $request->attributes->get('stock_actor');
            if (StockOperations::isCatalog($request)) {
                $record = $catalog->execute($request, $actor, app(StockCatalogCommandRequest::class)->validated());
                $response = StockCatalog::response($request->attributes->get('stock_catalog_name'), $record);
            } else {
                $record = $flow->execute($request, $actor, app(StockOperationCommandRequest::class)->validated());
                $response = StockOperations::response($record);
            }

            return $response->setStatusCode(StockOperations::action($request) === 'store' ? 201 : 200);
        });
    }

    private function storeIds(Request $request): Builder
    {
        return StockStoreAccess::query()->select('store_id')->where('user_id', $request->user('web')->id)->where('is_active', true);
    }

    private function positionFilters(ListStockRequest $request, \Illuminate\Database\Query\Builder $query): void
    {
        $query->whereIn('p.store_id', $this->storeIds($request));
        foreach (['store_id','drug_id','batch_id'] as $field) {
            if ($request->has($field)) {
                $query->where('p.'.$field, $request->integer($field));
            }
        }
    }

    private function limit(ListStockRequest $request): int
    {
        return $request->integer('per_page', config('api.pagination.per_page'));
    }
}
