<?php

namespace Tests;

use App\Models\Encounter;
use App\Models\ServicePrice;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

abstract class BillingTestCase extends TestCase
{
    protected User $cashier;
    protected User $reviewer;
    protected Encounter $visit;
    protected ServicePrice $price;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $permissions = array_values(array_filter(array_keys(config('authorization.permissions')), fn (string $code): bool => str_starts_with($code, 'billing.')));
        $this->grantRolePermissions('frontdesk', [...$permissions, 'patients.view', 'encounters.view', 'encounters.view_any']);
        $this->cashier = $this->createStaffWithRole('frontdesk');
        $this->reviewer = $this->createStaffWithRole('frontdesk');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHour()]);
        $this->price = ServicePrice::factory()->create(['service_id' => \App\Models\Service::factory()->create(['category' => 'consultation'])->id]);
    }

    protected function loginCashier(?User $actor = null): void
    {
        $this->flushSession();
        $this->actingAs($actor ?? $this->cashier);
    }

    protected function path(string $suffix = ''): string
    {
        return '/api/v1/billing/patients/'.$this->visit->patient_id.$suffix;
    }

    protected function writeBill(int $version, string $suffix, array $data = [], ?User $actor = null, array $headers = []): TestResponse
    {
        $this->loginCashier($actor);

        return $this->postCommand($this->path($suffix), ['account_version' => $version, 'reason' => 'Private synthetic financial reason',
            'current_password' => self::STAFF_TEST_PASSWORD] + $data, $headers);
    }

    protected function performed(int $version = 0, string $reference = 'Synthetic-service-1'): array
    {
        return $this->writeBill($version, '/performed-services', ['encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'service_id' => $this->price->service_id, 'quantity' => '2', 'occurred_at' => now('UTC')->toISOString(),
            'performed_confirmed' => true, 'reference' => $reference])->assertCreated()->json('data');
    }

    protected function invoice(array $source, array $headers = []): TestResponse
    {
        return $this->writeBill($source['version'], '/invoices', ['encounter_id' => $this->visit->id, 'encounter_version' => 1,
            'self_pay_confirmed' => true, 'sources' => [['type' => 'performed', 'id' => $source['last_record_id'], 'price_id' => $this->price->id]]], headers: $headers);
    }

    protected function receipt(int $version, string $amount = '250.00', array $changes = [], array $headers = []): TestResponse
    {
        return $this->writeBill($version, '/receipts', array_replace(['amount' => $amount, 'kind' => 'deposit', 'method' => 'cash',
            'payer_name' => 'Synthetic external payer', 'payer_phone' => '08000000000', 'paid_at' => now('UTC')->subMinute()->toISOString(),
            'externally_confirmed' => false, 'transaction_details' => ['receipt_number' => 'Synthetic paper receipt']], $changes), headers: $headers);
    }
}
