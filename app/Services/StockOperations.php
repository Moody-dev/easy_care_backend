<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\StockOperationResource;
use App\Models\Drug;
use App\Models\DrugBatch;
use App\Models\DrugPack;
use App\Models\StockMovement;
use App\Models\StockOperation;
use App\Models\StockOperationLine;
use App\Models\StockOperationRevision;
use App\Models\Supplier;
use App\Models\User;
use App\Support\FixedPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;

class StockOperations
{
    public function __construct(private StockAuthority $authority, private StockCatalog $catalog, private AuditTrail $audit) {}

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public static function isCatalog(Request $request): bool
    {
        return str_starts_with($request->route()->getName(), 'api.v1.stock.catalog.') || self::action($request) === 'access';
    }

    public static function isCommand(string $route): bool
    {
        return str_starts_with($route, 'api.v1.stock.') && in_array(substr($route, strrpos($route, '.') + 1), ['store','update','submit','approve','reject','cancel','access'], true);
    }

    public function authorize(Request $request, User $actor): void
    {
        $request->attributes->set('stock_actor', $actor);
        if (self::isCatalog($request)) {
            $this->catalog->authorize($request, $actor);

            return;
        }
        $this->authority->permission($actor, 'stock.view');
        $action = self::action($request);
        $operation = $action === 'store' ? null : StockOperation::query()->visibleTo($actor)->lockForUpdate()->findOrFail($request->route('stockOperation'));
        $kind = $operation?->kind ?? $request->input('kind');
        if (in_array($action, ['approve','reject'], true)) {
            $this->authority->permission($actor, 'stock.approve');
            if ($kind === 'requisition') {
                $this->authority->permission($actor, 'stock.requisitions.approve');
            }
            abort_if($operation->prepared_by === $actor->id, 403);
        } else {
            $this->authority->permission($actor, $kind === 'requisition' ? 'stock.requisitions.create' : 'stock.prepare');
            abort_if($operation !== null && $operation->prepared_by !== $actor->id, 403);
        }
        if ($operation !== null) {
            $this->authority->stores($actor, [$operation->source_store_id, $operation->destination_store_id], false);
        }
        if (in_array($action, ['approve','reject','cancel'], true)) {
            $this->authority->password($request, $actor);
        }
        $request->attributes->set('stock_operation', $operation);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): StockOperation
    {
        $action = self::action($request);
        $operation = $request->attributes->get('stock_operation');
        if ($operation !== null) {
            $this->authority->version($operation->version, $data['version']);
        }
        if (in_array($action, ['store','update'], true)) {
            if ($operation !== null && $operation->status !== 'draft') {
                throw new WriteConflict('stock_document_frozen', 'Only the author’s saved draft can be edited.');
            }
            if ($operation !== null && $operation->kind !== $data['kind']) {
                throw ValidationException::withMessages(['kind' => ['The document kind stays fixed.']]);
            }
            [$header, $previous] = $this->header($actor, $data);
            $lines = $this->lines($data['items'], $header, false);
            $time = $this->authority->time(...[...$previous, $operation?->last_changed_at]);
            $operation ??= new StockOperation;
            $new = ! $operation->exists;
            if (! $new) {
                $operation->lines()->delete();
            }
            $operation->forceFill($header + ['status' => 'draft', 'version' => ($operation->version ?? 0) + 1,
                'prepared_by' => $operation->prepared_by ?? $actor->id, 'prepared_at' => $operation->prepared_at ?? $time,
                'last_actor_id' => $actor->id, 'last_changed_at' => $time, 'reason' => $data['reason']])->save();
            foreach ($lines as $lineData) {
                $line = new StockOperationLine;
                $line->forceFill($lineData + ['operation_id' => $operation->id])->save();
            }
            $this->revision($operation, $new ? 'created' : 'updated');

            return $operation->fresh('lines.movements');
        }
        $time = $this->authority->time($operation->last_changed_at);
        if ($action === 'cancel') {
            if (! in_array($operation->status, ['draft','submitted'], true)) {
                throw new WriteConflict('stock_transition_conflict', 'This document can no longer be cancelled.');
            }
            $changes = ['status' => 'cancelled'];
        } elseif ($action === 'reject') {
            if ($operation->status !== 'submitted') {
                throw new WriteConflict('stock_transition_conflict', 'Only a submitted document can be rejected.');
            }
            $changes = ['status' => 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => $time];
        } else {
            $expected = $action === 'submit' ? 'draft' : 'submitted';
            if ($operation->status !== $expected) {
                throw new WriteConflict('stock_transition_conflict', 'Reload the document’s current state before that action.');
            }
            $values = $operation->only(['kind','source_store_id','destination_store_id','source_store_version','destination_store_version',
                'supplier_id','supplier_version','invoice_number','currency','reference']);
            $preparer = User::query()->lockForUpdate()->findOrFail($operation->prepared_by);
            [$header, $previous] = $this->header($actor, $values, $preparer);
            $savedLines = $operation->lines()->orderBy('batch_id')->get();
            $items = $savedLines->map(function (StockOperationLine $line): array {
                $row = ['batch_id' => $line->batch_id, 'drug_version' => $line->drug_version, 'source_balance_version' => $line->source_balance_version];
                if ($line->pack_id === null) {
                    $row['quantity'] = FixedPoint::format($line->quantity_micros);
                } else {
                    $row += ['pack_id' => $line->pack_id, 'pack_count' => FixedPoint::format($line->pack_count_micros)];
                }
                if ($line->unit_cost_micros !== null) {
                    $row['unit_cost'] = FixedPoint::format($line->unit_cost_micros);
                }

                return $row;
            })->all();
            if ($items === []) {
                throw ValidationException::withMessages(['items' => ['Supply at least one stock line.']]);
            }
            $checkedLines = $this->lines($items, $header, $action === 'approve');
            $consistent = StockCatalog::fingerprint($header['catalog_snapshot']) === StockCatalog::fingerprint($operation->catalog_snapshot);
            foreach ($savedLines as $index => $savedLine) {
                $consistent = $consistent && $savedLine->quantity_micros === $checkedLines[$index]['quantity_micros']
                    && StockCatalog::fingerprint($savedLine->catalog_snapshot) === StockCatalog::fingerprint($checkedLines[$index]['catalog_snapshot']);
            }
            if (! $consistent) {
                throw new WriteConflict('stock_document_mismatch', 'The saved quantities or catalog snapshot do not match their source records. Review the draft.');
            }
            $time = $this->authority->time(...[...$previous, $operation->last_changed_at]);
            if ($action === 'approve') {
                return $this->post($operation, $actor, $time, $data['reason']);
            }
            $changes = ['status' => 'submitted'];
        }
        $operation->forceFill($changes + ['version' => $operation->version + 1, 'last_actor_id' => $actor->id,
            'last_changed_at' => $time, 'reason' => $data['reason']])->save();
        $this->revision($operation, match ($action) { 'submit' => 'submitted', 'cancel' => 'cancelled', 'reject' => 'rejected' });

        return $operation->fresh('lines.movements');
    }

    /** @param array<string, mixed> $data @return array{array<string, mixed>, list<mixed>} */
    private function header(User $actor, array $data, ?User $preparer = null): array
    {
        $kind = $data['kind'];
        $source = isset($data['source_store_id']) ? (int) $data['source_store_id'] : null;
        $destination = isset($data['destination_store_id']) ? (int) $data['destination_store_id'] : null;
        $incoming = in_array($kind, ['receipt','opening','adjustment_in'], true);
        $outgoing = in_array($kind, ['damage','expiry','adjustment_out'], true);
        if ($incoming && ($source !== null || $destination === null) || $outgoing && ($source === null || $destination !== null)
            || ! $incoming && ! $outgoing && ($source === null || $destination === null || $source === $destination)) {
            throw ValidationException::withMessages(['source_store_id' => ['Choose the stores required by this document kind.']]);
        }
        $stores = $this->authority->stores($actor, [$source, $destination]);
        $previous = [];
        foreach (['source' => $source, 'destination' => $destination] as $prefix => $id) {
            if ($id !== null) {
                $this->authority->version($stores[$id]->version, $data[$prefix.'_store_version'] ?? 0);
                $previous[] = $stores[$id]->updated_at;
            } elseif (isset($data[$prefix.'_store_version'])) {
                throw ValidationException::withMessages([$prefix.'_store_version' => ['Do not supply a version for an absent store.']]);
            }
        }
        if ($kind === 'requisition') {
            $owner = $preparer ?? $actor;
            abort_unless($stores[$destination]->kind === 'department' && $owner->department_id !== null
                && $stores[$destination]->department_id === $owner->department_id, 403);
        }
        $supplier = null;
        if ($kind === 'receipt') {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id'] ?? null);
            $this->authority->version($supplier->version, $data['supplier_version'] ?? 0);
            if (! $supplier->is_active || ! StockCatalog::approved('suppliers', $supplier)) {
                throw new WriteConflict('stock_supplier_unavailable', 'Select a current active published supplier.');
            }
            if (empty($data['invoice_number']) || empty($data['currency'])) {
                throw ValidationException::withMessages(['invoice_number' => ['A supplier invoice and currency are required.']]);
            }
            $previous[] = $supplier->updated_at;
        } elseif (isset($data['supplier_id']) || isset($data['supplier_version']) || isset($data['invoice_number'])) {
            throw ValidationException::withMessages(['supplier_id' => ['Supplier invoices belong to receipts.']]);
        }
        $snapshots = [];
        foreach ($stores as $id => $store) {
            $snapshots[$id] = StockCatalog::snapshot('stores', $store);
        }

        return [['kind' => $kind, 'source_store_id' => $source, 'destination_store_id' => $destination,
            'source_store_version' => $source === null ? null : (int) $data['source_store_version'],
            'destination_store_version' => $destination === null ? null : (int) $data['destination_store_version'],
            'supplier_id' => $supplier?->id, 'supplier_version' => $supplier?->version,
            'invoice_number' => $data['invoice_number'] ?? null, 'currency' => $data['currency'] ?? null, 'reference' => $data['reference'],
            'catalog_snapshot' => ['stores' => $snapshots, 'supplier' => $supplier === null ? null : StockCatalog::snapshot('suppliers', $supplier)]], $previous];
    }

    /** @param list<array<string, mixed>> $items @param array<string, mixed> $header @return list<array<string, mixed>> */
    private function lines(array $items, array $header, bool $posting): array
    {
        $ids = array_map(fn (array $row): int => (int) $row['batch_id'], $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['items' => ['Choose each manufacturer batch only once per document.']]);
        }
        $batches = DrugBatch::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $result = [];
        foreach ($items as $index => $item) {
            $batch = $batches->get((int) $item['batch_id']) ?? DrugBatch::query()->findOrFail($item['batch_id']);
            $drug = Drug::query()->lockForUpdate()->findOrFail($batch->drug_id);
            $this->authority->version($drug->version, $item['drug_version']);
            if (! $drug->is_active || ! StockCatalog::approved('drugs', $drug) || ! StockCatalog::approved('batches', $batch)) {
                throw new WriteConflict('stock_catalog_unavailable', 'Select current active published medication and batch records.');
            }
            $pack = null;
            $packCount = null;
            if (isset($item['pack_id'])) {
                $pack = DrugPack::query()->lockForUpdate()->findOrFail($item['pack_id']);
                $latest = DrugPack::query()->where('drug_id', $drug->id)->where('code', $pack->code)->orderByDesc('version')->lockForUpdate()->first();
                if ($pack->drug_id !== $drug->id || $latest?->id !== $pack->id || ! $pack->is_active || ! StockCatalog::approved('packs', $pack)) {
                    throw new WriteConflict('stock_pack_unavailable', 'Choose the current active published pack conversion.');
                }
                $packCount = $this->authority->decimal($item['pack_count'] ?? null, 'items.'.$index.'.pack_count', true);
                try {
                    $quantity = FixedPoint::parse($pack->convertToBase($item['pack_count']));
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages(['items.'.$index.'.pack_count' => ['This conversion would require rounding or exceed the quantity limit.']]);
                }
            } else {
                $quantity = $this->authority->decimal($item['quantity'] ?? null, 'items.'.$index.'.quantity', true);
            }
            $this->catalog->precision($drug, $quantity);
            $cost = isset($item['unit_cost']) ? $this->authority->decimal($item['unit_cost'], 'items.'.$index.'.unit_cost') : null;
            if ($cost !== null && $header['currency'] === null || in_array($header['kind'], ['receipt','opening'], true)
                && ($cost === null || $header['currency'] === null)) {
                throw ValidationException::withMessages(['currency' => ['Supply the exact per-base-unit cost and currency for this document.']]);
            }
            $positiveOrTransfer = $header['destination_store_id'] !== null;
            if ($positiveOrTransfer && $batch->expires_on->toDateString() <= $this->authority->today()) {
                throw new WriteConflict('stock_batch_expired', 'Receipts, additions and transfers require a batch expiring after today in Africa/Lagos.');
            }
            if ($header['kind'] === 'expiry' && $batch->expires_on->toDateString() > $this->authority->today()) {
                throw new WriteConflict('stock_batch_not_expired', 'An expiry write-off requires the batch expiry date to have been reached.');
            }
            $previous = [$batch->recorded_at, $drug->updated_at, $pack?->recorded_at];
            $sourceVersion = null;
            foreach (['source_store_id','destination_store_id'] as $position) {
                if ($header[$position] === null) {
                    continue;
                }
                $head = DB::table('stock_position_heads')->where('store_id', $header[$position])->where('batch_id', $batch->id)->lockForUpdate()->first();
                $previous[] = $head?->recorded_at;
                $balance = (int) ($head?->quantity_micros ?? 0);
                if ($position === 'source_store_id') {
                    if (! isset($item['source_balance_version'])) {
                        throw ValidationException::withMessages(['items.'.$index.'.source_balance_version' => ['Supply the source balance version.']]);
                    }
                    $sourceVersion = (int) $item['source_balance_version'];
                    if ((int) ($head?->movement_count ?? 0) !== $sourceVersion) {
                        throw new WriteConflict('stock_balance_changed', 'The source stock changed. Review the current balance and prepare a new submission.');
                    }
                    if ($posting && $quantity > $balance) {
                        throw new WriteConflict('stock_insufficient', 'The selected batch has insufficient stock in the source store.');
                    }
                } elseif ($posting && $balance + $quantity > FixedPoint::MAX) {
                    throw new WriteConflict('stock_balance_limit', 'This addition exceeds the supported position balance.');
                }
                if ($header['kind'] === 'opening' && (int) ($head?->movement_count ?? 0) !== 0) {
                    throw new WriteConflict('stock_opening_exists', 'Opening stock is allowed only before any movement in this store and batch.');
                }
            }
            $this->authority->time(...$previous);
            $result[] = ['drug_id' => $drug->id, 'drug_version' => $drug->version, 'batch_id' => $batch->id, 'pack_id' => $pack?->id,
                'pack_count_micros' => $packCount, 'quantity_micros' => $quantity, 'unit_cost_micros' => $cost,
                'source_balance_version' => $sourceVersion, 'catalog_snapshot' => ['drug' => StockCatalog::snapshot('drugs', $drug),
                    'batch' => StockCatalog::snapshot('batches', $batch), 'pack' => $pack === null ? null : StockCatalog::snapshot('packs', $pack)]];
        }

        return $result;
    }

    private function post(StockOperation $operation, User $actor, \Carbon\CarbonImmutable $time, string $reason): StockOperation
    {
        $operation->forceFill(['status' => 'posting', 'version' => $operation->version + 1, 'reviewed_by' => $actor->id,
            'reviewed_at' => $time, 'last_actor_id' => $actor->id, 'last_changed_at' => $time, 'reason' => $reason])->save();
        $this->revision($operation, 'posting', false);
        if ($operation->kind === 'receipt') {
            $key = hash('sha256', mb_strtoupper(trim($operation->invoice_number)));
            if (DB::table('stock_invoice_receipts')->where('supplier_id', $operation->supplier_id)->where('invoice_key', $key)->lockForUpdate()->exists()) {
                throw new WriteConflict('stock_invoice_posted', 'This supplier invoice already has a posted receipt.');
            }
            DB::table('stock_invoice_receipts')->insert(['operation_id' => $operation->id, 'supplier_id' => $operation->supplier_id, 'invoice_key' => $key]);
        }
        foreach ($operation->lines()->orderBy('batch_id')->get() as $line) {
            foreach (['source_store_id' => -1, 'destination_store_id' => 1] as $field => $sign) {
                $store = $operation->{$field};
                if ($store === null) {
                    continue;
                }
                $movement = new StockMovement;
                $movement->forceFill(['movement_key' => hash('sha256', 'stock-operation|'.$operation->id.'|'.$line->id.'|'.$store),
                    'drug_id' => $line->drug_id, 'batch_id' => $line->batch_id, 'store_id' => $store, 'pack_id' => $line->pack_id,
                    'kind' => match ($operation->kind) { 'transfer','requisition' => 'transfer', 'adjustment_in','adjustment_out' => 'adjustment', default => $operation->kind },
                    'quantity_micros' => $sign * $line->quantity_micros, 'unit_cost_micros' => $line->unit_cost_micros,
                    'currency' => $line->unit_cost_micros === null ? null : $operation->currency,
                    'supplier_id' => $operation->supplier_id, 'reversal_of' => null, 'reference_type' => 'stock_operation_line',
                    'reference_id' => (string) $line->id, 'created_by' => $actor->id, 'recorded_at' => $time, 'reason' => $reason])->save();
            }
        }
        $operation->forceFill(['status' => 'posted', 'version' => $operation->version + 1, 'posted_at' => $time])->save();
        $this->revision($operation, 'posted');

        return $operation->fresh('lines.movements');
    }

    private function revision(StockOperation $operation, string $action, bool $audit = true): void
    {
        $operation->load('lines.movements');
        $revision = new StockOperationRevision;
        $revision->forceFill(['operation_id' => $operation->id, 'version' => $operation->version, 'action' => $action,
            'actor_id' => $operation->last_actor_id, 'recorded_at' => $operation->last_changed_at, 'reason' => $operation->reason,
            'snapshot' => self::snapshot($operation)])->save();
        if ($audit) {
            $this->audit->record('stock.'.$action, $operation->last_actor_id, 'stock_operation', $operation->id,
                ['version' => $operation->version, 'record_id' => $revision->id]);
        }
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        if (self::isCatalog($request)) {
            $catalog = $request->attributes->get('stock_catalog_name');
            $record = StockCatalog::query($catalog)->lockForUpdate()->findOrFail($receipt->resource_id);
            $this->authority->version($record->version ?? 1, $receipt->resource_version);

            return StockCatalog::response($catalog, $record);
        }
        $operation = StockOperation::query()->visibleTo($actor)->lockForUpdate()->findOrFail($receipt->resource_id);
        $this->authority->version($operation->version, $receipt->resource_version);

        return self::response($operation);
    }

    public static function response(StockOperation $operation): JsonResponse
    {
        return (new StockOperationResource($operation->loadMissing('lines.movements')))->response();
    }

    /** @return array<string, mixed> */
    public static function snapshot(StockOperation $operation): array
    {
        $values = $operation->only(['id','kind','status','version','source_store_id','destination_store_id','source_store_version','destination_store_version',
            'supplier_id','supplier_version','invoice_number','currency','reference','prepared_by','reviewed_by','last_actor_id','reason','catalog_snapshot']);
        foreach (['prepared_at','reviewed_at','posted_at','last_changed_at'] as $field) {
            $values[$field] = $operation->{$field}?->toISOString();
        }
        $operation->loadMissing('lines.movements');
        $values['items'] = $operation->lines->map(fn (StockOperationLine $line): array => $line->only(['id','drug_id','drug_version','batch_id','pack_id','source_balance_version','catalog_snapshot'])
            + ['quantity' => FixedPoint::format($line->quantity_micros), 'pack_count' => $line->pack_count_micros === null ? null : FixedPoint::format($line->pack_count_micros),
                'unit_cost' => $line->unit_cost_micros === null ? null : FixedPoint::format($line->unit_cost_micros),
                'movement_ids' => $line->movements->pluck('id')->all()])->all();

        return $values;
    }
}
