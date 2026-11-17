<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\BillingTestCase;

abstract class HmoTestCase extends BillingTestCase
{


    protected int $providerId;
    protected int $planId;
    protected int $importId;
    protected int $tariffId;
    protected int $coverageId;

    protected function setUp(): void
    {
        parent::setUp();
        $permissions = array_filter(array_keys(config('authorization.permissions')), fn (string $code): bool => str_starts_with($code, 'hmo.'));
        $this->grantRolePermissions('frontdesk', array_values($permissions));
    }

    protected function command(string $suffix, array $data = [], ?User $actor = null, array $headers = []): TestResponse
    {
        $this->loginCashier($actor);

        return $this->postCommand('/api/v1/hmo'.$suffix, $data + [
            'reason' => 'Synthetic outside insurer record', 'current_password' => self::STAFF_TEST_PASSWORD,
        ], $headers);
    }

    protected function patientCommand(int $version, string $suffix, array $data = [], ?User $actor = null, array $headers = []): TestResponse
    {
        return $this->command('/patients/'.$this->visit->patient_id.$suffix, ['account_version' => $version] + $data, $actor, $headers);
    }

    protected function csv(bool $preauth = false): string
    {
        return "item_type,item_code,currency,unit_price,preauth_required\nservice,".$this->price->service->code.",NGN,75.000000,".($preauth ? '1' : '0')."\n";
    }

    protected function setupCatalog(bool $preauth = false): void
    {
        $this->providerId = $this->command('/providers', ['code' => 'synthetic-provider', 'name' => 'Synthetic provider', 'is_active' => true, 'preauth_required' => false])
            ->assertCreated()->json('data.id');
        $this->planId = $this->command('/plans', ['provider_id' => $this->providerId, 'code' => 'synthetic-plan', 'name' => 'Synthetic plan', 'is_active' => true, 'preauth_required' => false])
            ->assertCreated()->json('data.id');
        $this->importId = $this->command('/imports', $this->importFields($this->csv($preauth)))->assertCreated()->json('data.id');
        $this->command('/imports/'.$this->importId.'/review', $this->reviewFields(), $this->reviewer)->assertCreated();
        $this->tariffId = DB::table('hmo_tariffs')->where('import_id', $this->importId)->value('id');
    }

    protected function importFields(string $csv, int $version = 0): array
    {
        return ['plan_id' => $this->planId, 'expected_version' => $version, 'filename' => 'synthetic-approved.csv',
            'csv_content' => $csv, 'approval_reference' => 'Synthetic hospital approval'];
    }

    protected function reviewFields(string $outcome = 'accepted'): array
    {
        return ['version' => 1, 'outcome' => $outcome, 'externally_verified' => true];
    }

    protected function coverage(): array
    {
        $data = $this->patientCommand(0, '/coverages', ['plan_id' => $this->planId, 'member_number' => 'SYN-MEMBER-1',
            'valid_from' => now()->subYear()->toDateString(), 'valid_until' => now()->addYear()->toDateString(),
            'verification_reference' => 'Synthetic insurer verification', 'verified' => true])->assertCreated()->json('data');
        $this->coverageId = $data['last_record_id'];

        return $data;
    }

    protected function splitFields(array $source): array
    {
        return ['coverage_id' => $this->coverageId, 'encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'split_confirmed' => true, 'sources' => [['type' => 'performed', 'id' => $source['last_record_id'], 'tariff_id' => $this->tariffId,
                'patient_amount' => '30.00', 'insurer_amount' => '120.00', 'benefit_reference' => 'Synthetic approved benefit split']]];
    }

    protected function hmoInvoice(bool $newCatalog = true): array
    {
        if ($newCatalog) { $this->setupCatalog(); }
        $coverage = $this->coverage();
        $source = $this->performed($coverage['version']);

        return $this->patientCommand($source['version'], '/invoices', $this->splitFields($source))->assertCreated()->json('data');
    }

    protected function submittedClaim(bool $newCatalog = true): array
    {
        $invoice = $this->hmoInvoice($newCatalog);
        $claim = $this->patientCommand($invoice['version'], '/claims', ['invoice_id' => $invoice['last_record_id'],
            'reference' => 'Synthetic-claim-'.$this->visit->patient_id, 'submitted_at' => now('UTC')->toISOString(), 'externally_submitted' => true])->assertCreated()->json('data');
        $claim['claim_id'] = $claim['last_record_id'];
        $claim['item_id'] = DB::table('billing_invoice_items')->where('invoice_id', $invoice['last_record_id'])->value('id');
        $claim['submission_id'] = DB::table('hmo_submissions')->where('claim_id', $claim['claim_id'])->value('id');

        return $claim;
    }

    protected function approvedClaim(bool $newCatalog = true): array
    {
        $claim = $this->submittedClaim($newCatalog);
        $decision = $this->patientCommand($claim['version'], '/decisions', $this->decision($claim))->assertCreated()->json('data');
        $reviewed = $this->patientCommand($decision['version'], '/decisions/'.$decision['last_record_id'].'/review', $this->reviewFields(), $this->reviewer)->assertCreated()->json('data');

        return array_replace($claim, ['version' => $reviewed['version']]);
    }

    protected function remittance(string $amount = '100.00'): array
    {
        $receipt = $this->command('/remittances', ['provider_id' => $this->providerId, 'amount' => $amount, 'reference' => 'Synthetic remittance 1',
            'method' => 'cash', 'payer_name' => 'Synthetic insurer', 'received_at' => now('UTC')->toISOString(),
            'externally_confirmed' => true, 'transaction_details' => ['receipt_number' => 'Synthetic remittance voucher']])->assertCreated()->json('data');

        return $this->command('/remittances/'.$receipt['id'].'/review', $this->reviewFields(), $this->reviewer)->assertCreated()->json('data');
    }

    protected function allocationFields(array $claim, array $receipt, string $amount = '80.00'): array
    {
        return ['claim_id' => $claim['claim_id'], 'remittance_id' => $receipt['id'], 'remittance_version' => $receipt['version'],
            'reference' => 'Synthetic allocation 1', 'lines' => [['item_id' => $claim['item_id'], 'amount' => $amount]]];
    }

    protected function decision(array $claim, string $amount = '100.00'): array
    {
        return ['claim_id' => $claim['claim_id'], 'submission_id' => $claim['submission_id'], 'reference' => 'Synthetic-response-'.$claim['claim_id'],
            'occurred_at' => now('UTC')->toISOString(), 'lines' => [['item_id' => $claim['item_id'], 'approved_amount' => $amount, 'reason' => 'Synthetic outside payer response']]];
    }

}
