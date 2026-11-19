<?php

namespace App\Services;

use App\Models\Bed;
use App\Models\HospitalPriceImport;
use App\Models\HospitalReferencePrice;
use App\Models\Ward;
use App\Support\FixedPoint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class HospitalPriceImporter
{
    public function __construct(private WriteTransactions $transactions, private AuditTrail $audit) {}

    /** @param array{hash: string, sources: array, rows: array} $parsed */
    public function apply(array $parsed, bool $confirmedDailyBeds): HospitalPriceImport
    {
        if (! $confirmedDailyBeds) {
            throw new InvalidArgumentException('Confirm the accommodation amounts are daily bed rates before applying this import.');
        }

        return $this->transactions->run(function () use ($parsed): HospitalPriceImport {
            DB::table('permissions')->where('code', 'staff.manage')->lockForUpdate()->sole();
            DB::table('permissions')->where('code', 'patients.create')->lockForUpdate()->sole();
            $existing = HospitalPriceImport::query()->where('content_hash', $parsed['hash'])->first();
            if ($existing !== null) {
                return $existing;
            }
            $batch = new HospitalPriceImport;
            $batch->forceFill(['content_hash' => $parsed['hash'], 'sources' => $parsed['sources'], 'effective_at' => now('UTC'), 'created_at' => now('UTC')])->save();
            $wards = [];
            foreach ($parsed['rows'] as $row) {
                $row['bed_id'] = null;
                if ($row['source_kind'] === 'accommodation' && $row['status'] === 'ready') {
                    $wardName = $row['group_name'];
                    if (! isset($wards[$wardName])) {
                        $code = 'price-import-'.substr(hash('sha256', mb_strtolower($wardName)), 0, 32);
                        $ward = Ward::query()->where('code', $code)->lockForUpdate()->first();
                        if ($ward === null) {
                            if (Ward::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($wardName)])->exists()) {
                                throw new InvalidArgumentException('An existing ward needs explicit identity mapping before this import can be applied.');
                            }
                            $ward = new Ward;
                            $ward->forceFill(['code' => $code, 'name' => $wardName])->save();
                            $this->audit->record('location.created', null, 'ward', $ward->id, channel: 'console');
                        }
                        if ($ward->name !== $wardName || ! $ward->is_active) {
                            throw new InvalidArgumentException('An imported ward identity has changed or is inactive.');
                        }
                        $wards[$wardName] = $ward;
                    }
                    $ward = $wards[$wardName];
                    $rate = substr(FixedPoint::format($row['amount_micros']), 0, -4);
                    $bed = Bed::query()->where('ward_id', $ward->id)->where('label', $row['label'])->lockForUpdate()->first();
                    if ($bed === null) {
                        $bed = new Bed;
                        $bed->forceFill(['ward_id' => $ward->id, 'label' => $row['label'], 'daily_rate' => $rate])->save();
                        $this->audit->record('location.created', null, 'bed', $bed->id, channel: 'console');
                    } elseif (! $bed->is_active || $bed->daily_rate !== $rate) {
                        throw new InvalidArgumentException('An existing bed differs from this source. Review the rate through the versioned location workflow.');
                    }
                    $row['bed_id'] = $bed->id;
                    $row['status'] = 'active_bed_rate';
                }
                $price = new HospitalReferencePrice;
                $price->forceFill($row + ['import_id' => $batch->id])->save();
            }
            $this->audit->record('prices.imported', null, 'billing_catalog', $batch->id, channel: 'console');

            return $batch;
        });
    }
}
