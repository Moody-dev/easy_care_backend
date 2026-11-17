<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\BillingTestCase;

class BillingWorkflowMigrationTest extends BillingTestCase
{
    use RefreshDatabase;

    public function test_rollback_refuses_to_erase_recorded_physical_refund_details(): void
    {
        $receipt = $this->receipt(0, '100.00')->assertCreated()->json('data');
        $request = $this->writeBill($receipt['version'], '/refunds', ['receipt_id' => $receipt['last_record_id'], 'amount' => '20.00'])->assertCreated()->json('data');
        $this->writeBill($request['version'], '/changes/'.$request['last_record_id'].'/approve', [
            'payout_confirmed' => true, 'payout_reference' => 'Synthetic physical voucher', 'payout_at' => now('UTC')->toISOString(),
            'payout_method' => 'cash', 'payee_name' => 'Synthetic actual recipient', 'payout_details' => []], $this->reviewer)->assertCreated();
        $migration = require database_path('migrations/2026_11_17_121111_add_billing_workflow_guards.php');
        try {
            $migration->down();
            $this->fail('A rollback must not discard actual refund transaction details.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('recorded physical-refund', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('billing_change_reviews', 'payout_method'));
        $this->assertDatabaseHas('billing_change_reviews', ['payee_name' => 'Synthetic actual recipient', 'payout_reference' => 'Synthetic physical voucher']);
    }

}
