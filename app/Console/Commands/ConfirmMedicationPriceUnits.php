<?php

namespace App\Console\Commands;

use App\Models\HospitalPriceImport;
use App\Services\AuditTrail;
use App\Services\WriteTransactions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('prices:confirm-medication-units {import : Retained price import ID} {--per-item : Confirm each medication amount is for one sellable item}')]
#[Description('Retain the hospital unit confirmation without changing original source rows or publishing clinical identities')]
class ConfirmMedicationPriceUnits extends Command
{
    public function handle(WriteTransactions $transactions, AuditTrail $audit): int
    {
        $import = HospitalPriceImport::query()->find($this->argument('import'));
        if ($import === null || ! $this->option('per-item')) {
            $this->error('Choose a retained import and confirm --per-item.');

            return self::FAILURE;
        }
        $transactions->run(function () use ($import, $audit): void {
            DB::table('permissions')->where('code', 'staff.manage')->lockForUpdate()->sole();
            if (! DB::table('hospital_medication_units')->where('import_id', $import->id)->exists()) {
                DB::table('hospital_medication_units')->insert(['import_id' => $import->id, 'basis' => 'per_sellable_item',
                    'confirmation' => 'Hospital confirms the supplied NGN medication amount is the selling price for one physical sellable item; dosing and pack conversions are not inferred.',
                    'recorded_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
                $audit->record('prices.units_confirmed', null, 'billing_catalog', $import->id, channel: 'console');
            }
        });
        $this->info('Confirmed: NGN per one sellable item. Source conflicts and staff catalog publication remain subject to review.');

        return self::SUCCESS;
    }
}
