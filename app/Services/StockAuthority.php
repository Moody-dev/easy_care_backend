<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\StockStore;
use App\Models\StockStoreAccess;
use App\Models\User;
use App\Support\FixedPoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StockAuthority
{
    public function password(Request $request, User $actor): void
    {
        $password = $request->input('current_password');
        if (! is_string($password) || strlen($password) > 1024 || ! Hash::check($password, $actor->password)) {
            throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
        }
    }

    public function permission(User $actor, string $permission): void
    {
        abort_unless($actor->is_active, 403);
        Gate::forUser($actor)->authorize($permission);
        $grant = DB::table('user_roles as ur')->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('ur.user_id', $actor->id)
            ->where('p.code', $permission)->select('p.id')->lockForUpdate()->first();
        abort_unless($grant !== null, 403);
    }

    public function version(int $actual, mixed $expected): void
    {
        if ($actual !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This saved record changed. Reload it before another submission.');
        }
    }

    /** @param list<int|null> $ids @return array<int, StockStore> */
    public function stores(User $actor, array $ids, bool $active = true): array
    {
        $stores = [];
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        foreach ($ids as $id) {
            $store = StockStore::query()->lockForUpdate()->findOrFail($id);
            StockStoreAccess::query()->where('user_id', $actor->id)->where('store_id', $id)->where('is_active', true)->lockForUpdate()->firstOrFail();
            if ($active && ! $store->is_active) {
                throw new WriteConflict('stock_store_unavailable', 'A selected store is inactive.');
            }
            $stores[$id] = $store;
        }

        return $stores;
    }

    public function decimal(mixed $value, string $field, bool $positive = false): int
    {
        try {
            $result = FixedPoint::parse($value);
            if ($positive && $result === 0) {
                throw new InvalidArgumentException;
            }

            return $result;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => ['Use a decimal string with at most nine whole and six fractional digits'.($positive ? ', greater than zero.' : '.')]]);
        }
    }

    public function time(mixed ...$previous): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        foreach ($previous as $value) {
            if ($value !== null && $now->lessThan($value)) {
                throw new WriteConflict('stock_clock_conflict', 'The server time precedes a saved stock event.');
            }
        }

        return $now;
    }

    public function today(): string
    {
        return CarbonImmutable::now('Africa/Lagos')->toDateString();
    }
}
