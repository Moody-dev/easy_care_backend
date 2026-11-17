<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Drug;
use App\Models\DrugBatch;
use App\Models\DrugPack;
use App\Models\DrugPrice;
use App\Models\StockCatalogRevision;
use App\Models\StockStore;
use App\Models\StockStoreAccess;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockCatalog
{
    public const MODELS = ['units' => StockUnit::class, 'drugs' => Drug::class, 'packs' => DrugPack::class, 'suppliers' => Supplier::class,
        'stores' => StockStore::class, 'batches' => DrugBatch::class, 'prices' => DrugPrice::class, 'store-access' => StockStoreAccess::class];

    public function __construct(private StockAuthority $authority, private AuditTrail $audit) {}

    public static function query(string $catalog): Builder
    {
        abort_unless(array_key_exists($catalog, self::MODELS), 404);

        return self::MODELS[$catalog]::query();
    }

    public function authorize(Request $request, User $actor): void
    {
        $catalog = $request->route('catalog') ?? 'store-access';
        $this->authority->permission($actor, 'stock.catalog.view');
        $this->authority->permission($actor, $catalog === 'store-access' ? 'stock.access.manage' : 'stock.catalog.manage');
        $this->authority->password($request, $actor);
        $record = null;
        if ($catalog === 'store-access') {
            StockStore::query()->lockForUpdate()->findOrFail($request->route('stockStore'));
            User::query()->lockForUpdate()->findOrFail($request->route('stockStaff'));
            $record = StockStoreAccess::query()->where('store_id', $request->route('stockStore'))->where('user_id', $request->route('stockStaff'))->lockForUpdate()->first();
        } elseif ($request->route('stockRecord') !== null) {
            $record = self::query($catalog)->lockForUpdate()->findOrFail($request->route('stockRecord'));
        } else {
            self::query($catalog);
        }
        $request->attributes->set('stock_catalog_name', $catalog);
        $request->attributes->set('stock_catalog_record', $record);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): Model
    {
        $catalog = $request->attributes->get('stock_catalog_name');
        $record = $request->attributes->get('stock_catalog_record');
        $time = $this->authority->time($record?->updated_at ?? $record?->changed_at ?? $record?->recorded_at);
        if ($catalog === 'store-access') {
            $this->authority->version($record?->version ?? 0, $data['version']);
            $target = User::query()->findOrFail($request->route('stockStaff'));
            abort_if((bool) $data['is_active'] && ! $target->is_active, 403);
            $record ??= new StockStoreAccess;
            $record->forceFill(['user_id' => (int) $request->route('stockStaff'), 'store_id' => (int) $request->route('stockStore'),
                'is_active' => $data['is_active'], 'version' => ($record->version ?? 0) + 1,
                'changed_by' => $actor->id, 'changed_at' => $time, 'reason' => $data['reason']])->save();
        } elseif ($record !== null) {
            if (! in_array($catalog, ['drugs','suppliers','stores'], true)) {
                throw ValidationException::withMessages(['version' => ['This catalog is immutable. Publish a new pack/price version or create a new identity.']]);
            }
            $this->authority->version($record->version, $data['version']);
            $allowed = match ($catalog) {
                'drugs' => ['is_active'],
                'stores' => ['name','is_active'],
                'suppliers' => ['name','is_active','contact_name','phone','alternate_phone','email','address'],
            };
            $changes = array_intersect_key($data, array_flip($allowed));
            $record->forceFill($changes);
            if (! $record->isDirty()) {
                throw ValidationException::withMessages(['version' => ['Supply a changed catalog value.']]);
            }
            $record->forceFill(['version' => $record->version + 1, 'updated_by' => $actor->id, 'updated_at' => $time, 'change_reason' => $data['reason']])->save();
        } else {
            $class = self::MODELS[$catalog];
            $record = new $class;
            $values = array_diff_key($data, array_flip(['current_password','reason','approval_reference','expected_version','amount','base_quantity','effective_at']));
            if (isset($values['code']) && in_array($catalog, ['units','drugs','suppliers','stores'], true)
                && self::query($catalog)->where('code', $values['code'])->lockForUpdate()->exists()) {
                throw new WriteConflict('stock_code_conflict', 'This catalog code already exists.');
            }
            if ($catalog === 'drugs') {
                $unit = StockUnit::query()->lockForUpdate()->findOrFail($data['base_unit_id']);
                $time = $this->authority->time($unit->recorded_at);
            }
            if ($catalog === 'stores' && isset($data['department_id'])) {
                \App\Models\Department::query()->findOrFail($data['department_id']);
            }
            if ($catalog === 'stores' && $data['kind'] === 'department' && empty($data['department_id'])) {
                throw ValidationException::withMessages(['department_id' => ['A department store requires its department.']]);
            }
            if (in_array($catalog, ['packs','prices','batches'], true)) {
                $drug = Drug::query()->lockForUpdate()->findOrFail($data['drug_id']);
                if (! $drug->is_active || ! self::approved('drugs', $drug)) {
                    throw new WriteConflict('stock_drug_unavailable', 'Select a current active published medication identity.');
                }
                $time = $this->authority->time($drug->updated_at);
                if ($catalog === 'batches' && DrugBatch::query()->where('drug_id', $drug->id)->where('manufacturer', $data['manufacturer'])
                    ->where('batch_number', $data['batch_number'])->lockForUpdate()->exists()) {
                    throw new WriteConflict('stock_batch_conflict', 'This manufacturer batch is already registered for this medication.');
                }
                if (in_array($catalog, ['packs','prices'], true)) {
                    $stream = $catalog === 'packs' ? ['code' => $data['code']] : ['kind' => $data['kind'], 'currency' => $data['currency']];
                    $latest = self::query($catalog)->where('drug_id', $drug->id)->where($stream)->orderByDesc('version')->lockForUpdate()->first();
                    $this->authority->version($latest?->version ?? 0, $data['expected_version']);
                    $time = $this->authority->time($drug->updated_at, $latest?->recorded_at);
                    $values['version'] = ($latest?->version ?? 0) + 1;
                    if ($catalog === 'packs') {
                        $quantity = $this->authority->decimal($data['base_quantity'], 'base_quantity', true);
                        $this->precision($drug, $quantity);
                        $values['base_quantity_micros'] = $quantity;
                    } else {
                        $values['amount_micros'] = $this->authority->decimal($data['amount'], 'amount');
                        $effective = isset($data['effective_at']) ? CarbonImmutable::parse($data['effective_at'])->utc() : $time;
                        if ($effective->lessThan($time) || $latest !== null && $effective->lessThan($latest->effective_at)) {
                            throw ValidationException::withMessages(['effective_at' => ['Use an effective time at or after recording and the previous price version.']]);
                        }
                        $values['effective_at'] = $effective;
                    }
                }
            }
            if (in_array($catalog, ['drugs','suppliers','stores'], true)) {
                $values += ['version' => 1, 'is_active' => true];
                $values += ['updated_by' => $actor->id, 'updated_at' => $time, 'change_reason' => $data['reason']];
            }
            $record->forceFill($values + ['created_by' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason']])->save();
        }
        $record = $record->fresh();
        $revision = new StockCatalogRevision;
        $revision->forceFill(['catalog' => $catalog, 'record_id' => $record->id, 'version' => $record->version ?? 1,
            'actor_id' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason'],
            'snapshot' => self::snapshot($catalog, $record) + ['approval_reference' => $data['approval_reference'] ?? null]])->save();
        $this->audit->record($catalog === 'store-access' ? 'stock.access_changed' : 'stock.catalog_changed', $actor->id, 'stock_catalog', $record->id,
            ['version' => $record->version ?? 1, 'record_id' => $revision->id]);

        return $record;
    }

    public function precision(Drug $drug, int $quantity): void
    {
        $step = 10 ** (6 - $drug->quantity_scale);
        if ($quantity % $step !== 0) {
            throw ValidationException::withMessages(['quantity' => ['This medication permits only '.$drug->quantity_scale.' quantity decimal places.']]);
        }
    }

    public static function approved(string $catalog, Model $record): bool
    {
        return StockCatalogRevision::query()->where('catalog', $catalog)->where('record_id', $record->id)->where('version', $record->version ?? 1)->exists();
    }

    /** @return array<string, mixed> */
    public static function snapshot(string $catalog, Model $record): array
    {
        $fields = match ($catalog) {
            'units' => ['code','name','created_by'],
            'drugs' => ['code','name','generic_name','brand_name','strength','dosage_form','category','base_unit_id','quantity_scale','is_active','created_by','updated_by'],
            'packs' => ['drug_id','code','name','is_active','created_by'],
            'suppliers' => ['code','name','contact_name','phone','alternate_phone','email','address','is_active','created_by','updated_by'],
            'stores' => ['code','name','department_id','kind','is_active','created_by','updated_by'],
            'batches' => ['drug_id','batch_number','manufacturer','created_by'],
            'prices' => ['drug_id','kind','currency','created_by'],
            'store-access' => ['user_id','store_id','is_active','changed_by'],
        };
        $values = ['id' => $record->id, 'catalog' => $catalog, 'version' => $record->version ?? 1] + $record->only($fields);
        foreach (['recorded_at','updated_at','changed_at','effective_at'] as $column) {
            if ($record->getAttribute($column) !== null) {
                $values[$column] = $record->{$column}->toISOString();
            }
        }
        if ($catalog === 'batches') {
            $values += ['manufactured_on' => $record->manufactured_on?->toDateString(), 'expires_on' => $record->expires_on->toDateString()];
        }
        if ($catalog === 'packs') {
            $values['base_quantity'] = FixedPoint::format($record->base_quantity_micros);
        }
        if ($catalog === 'prices') {
            $values['amount'] = FixedPoint::format($record->amount_micros);
        }

        return $values;
    }

    public static function response(string $catalog, Model $record): JsonResponse
    {
        return response()->json(['data' => self::snapshot($catalog, $record)]);
    }

    public static function fingerprint(mixed $value): string
    {
        $normalize = function (mixed $node) use (&$normalize): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (! array_is_list($node)) {
                ksort($node, SORT_STRING);
            }

            return array_map($normalize, $node);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
