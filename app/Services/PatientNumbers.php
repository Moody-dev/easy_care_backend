<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Models\Patient;
use App\Models\PatientCategory;
use App\Models\PatientIdentifier;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;

class PatientNumbers
{
    public const PREFIXES = ['private' => 'PVT', 'walk-in' => 'WLK'];

    public function __construct(private WriteTransactions $transactions, private AuditTrail $audit) {}

    public function reserve(User $actor, int $categoryId, ?string $key): JsonResponse
    {
        if (! is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => ['Supply an Idempotency-Key header of 16 to 128 supported characters.']]);
        }

        return $this->transactions->run(function () use ($actor, $categoryId, $key): JsonResponse {
            abort_if(Permission::where('code', 'staff.manage')->lockForUpdate()->first() === null, 503);
            abort_if(Permission::where('code', 'patients.create')->lockForUpdate()->first() === null, 503);
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->is_active, 403);
            Gate::forUser($actor)->authorize('create', Patient::class);
            $category = PatientCategory::query()->lockForUpdate()->findOrFail($categoryId);
            $prefix = self::PREFIXES[$category->code] ?? null;
            if ($prefix === null) {
                throw ValidationException::withMessages(['category_id' => ['Automatic numbers are enabled only for Private and Walk-in.']]);
            }
            $existing = DB::table('patient_number_reservations')->where('requested_by', $actor->id)->where('request_key', hash('sha256', $key))->lockForUpdate()->first();
            if ($existing !== null) {
                if ((int) $existing->category_id !== $categoryId) {
                    throw new WriteConflict('idempotency_conflict', 'This reservation key was used for another category.');
                }
                if ($existing->patient_id !== null) {
                    throw new WriteConflict('number_reservation_consumed', 'This reservation has already registered a patient. Restore the original registration result.');
                }

                return $this->response($existing, true);
            }
            DB::table('patient_number_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_value' => 0]);
            $sequence = DB::table('patient_number_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
            abort_if($sequence === null, 503);
            $maximum = (int) $sequence->last_value;
            foreach (PatientIdentifier::where('identifier', 'like', $prefix.'-%')->pluck('identifier') as $identifier) {
                if (preg_match('/^'.preg_quote($prefix, '/').'-([0-9]+)$/D', $identifier, $match) === 1) {
                    $digits = ltrim($match[1], '0');
                    if (strlen($digits) > 18) {
                        throw new WriteConflict('number_sequence_exhausted', 'The existing patient number range needs administrator review.');
                    }
                    $maximum = max($maximum, (int) $digits);
                }
            }
            if ($maximum >= 999999999999999999) {
                throw new WriteConflict('number_sequence_exhausted', 'The patient number range needs administrator review.');
            }
            $number = $prefix.'-'.str_pad((string) ($maximum + 1), 2, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));
            DB::table('patient_number_sequences')->where('prefix', $prefix)->update(['last_value' => $maximum + 1]);
            $id = DB::table('patient_number_reservations')->insertGetId(['category_id' => $categoryId, 'requested_by' => $actor->id,
                'number' => $number, 'request_key' => hash('sha256', $key), 'token_hash' => hash('sha256', $token),
                'encrypted_token' => Crypt::encryptString($token), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $this->audit->record('patient.number_reserved', $actor->id, 'patient', null, ['category_id' => $categoryId, 'record_id' => $id]);

            return $this->response(DB::table('patient_number_reservations')->find($id), false);
        });
    }

    public function claim(User $actor, PatientCategory $category, array $data): ?stdClass
    {
        if (! isset(self::PREFIXES[$category->code])) {
            if (! empty($data['number_reservation_token'])) {
                throw ValidationException::withMessages(['number_reservation_token' => ['This category uses staff-entered numbers.']]);
            }
            if (empty($data['mrn'])) {
                throw ValidationException::withMessages(['mrn' => ['Supply a patient number for this category.']]);
            }
            $this->assertNotReserved($data['mrn']);

            return null;
        }
        $token = $data['number_reservation_token'] ?? '';
        $reservation = DB::table('patient_number_reservations')->where('token_hash', hash('sha256', $token))->where('requested_by', $actor->id)->lockForUpdate()->first();
        if ($reservation === null || (int) $reservation->category_id !== $category->id) {
            throw ValidationException::withMessages(['number_reservation_token' => ['Reserve a number for this category using the current staff session.']]);
        }
        if ($reservation->patient_id !== null) {
            throw new WriteConflict('number_reservation_consumed', 'This number was already used for a registration. Retry the original registration key or reserve a new number.');
        }
        if (isset($data['mrn']) && $data['mrn'] !== $reservation->number) {
            throw ValidationException::withMessages(['mrn' => ['The reserved patient number is read-only.']]);
        }

        return $reservation;
    }

    public function assertNotReserved(string $number): void
    {
        if (DB::table('patient_number_reservations')->where('number', $number)->exists()) {
            throw ValidationException::withMessages(['mrn' => ['This patient number has already been reserved.']]);
        }
    }

    private function response(stdClass $reservation, bool $replayed): JsonResponse
    {
        return response()->json(['data' => ['id' => (int) $reservation->id, 'category_id' => (int) $reservation->category_id,
            'mrn' => $reservation->number, 'number_reservation_token' => Crypt::decryptString($reservation->encrypted_token),
            'numbering_mode' => 'reserved', 'read_only' => true]], $replayed ? 200 : 201)
            ->header('Cache-Control', 'no-store, private')->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }
}
