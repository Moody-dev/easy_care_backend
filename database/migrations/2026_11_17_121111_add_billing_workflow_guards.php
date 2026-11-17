<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('billing_change_reviews', function (Blueprint $table): void {
            $table->char('payout_key', 64)->nullable()->unique();
            $table->string('payout_method', 24)->nullable();
            $table->string('payee_name', 191)->nullable();
            $table->json('payout_details')->nullable();
        });
        $this->guard('billing_change_reviews', 'billing_payout_confirmation', "NOT EXISTS (SELECT 1 FROM billing_changes c WHERE c.id = NEW.change_id
            AND ((c.kind = 'refund' AND NEW.decision = 'approved' AND NEW.payout_confirmed = 1 AND NEW.payout_key IS NOT NULL
                AND NEW.payout_reference IS NOT NULL AND NEW.payout_at IS NOT NULL AND NEW.payout_at <= NEW.recorded_at
                AND NEW.payout_method IN ('cash','bank_transfer','pos','other') AND NEW.payee_name IS NOT NULL AND NEW.payout_details IS NOT NULL)
            OR ((c.kind <> 'refund' OR NEW.decision <> 'approved') AND NEW.payout_confirmed = 0
                AND NEW.payout_key IS NULL AND NEW.payout_reference IS NULL AND NEW.payout_at IS NULL
                AND NEW.payout_method IS NULL AND NEW.payee_name IS NULL AND NEW.payout_details IS NULL)))");
        $this->guard('billing_events', 'billing_event_sequence', "NEW.version NOT BETWEEN 1 AND 4294967293 OR (NEW.version > 1 AND NOT EXISTS (
            SELECT 1 FROM billing_events e WHERE e.account_id = NEW.account_id AND e.version = NEW.version - 1 AND e.recorded_at <= NEW.recorded_at))");
        $bindings = [
            'performed' => "SELECT 1 FROM billing_performed_services r WHERE r.id = NEW.record_id AND r.account_id = NEW.account_id",
            'invoice' => "SELECT 1 FROM billing_invoices r JOIN billing_invoice_posts p ON p.invoice_id = r.id WHERE r.id = NEW.record_id AND r.account_id = NEW.account_id",
            'receipt' => "SELECT 1 FROM billing_receipts r WHERE r.id = NEW.record_id AND r.account_id = NEW.account_id",
            'allocate' => "SELECT 1 FROM billing_allocations r JOIN billing_receipts p ON p.id = r.receipt_id WHERE r.id = NEW.record_id AND p.account_id = NEW.account_id",
            'credit-request' => "SELECT 1 FROM billing_changes r WHERE r.id = NEW.record_id AND r.account_id = NEW.account_id AND r.kind = 'credit'",
            'refund-request' => "SELECT 1 FROM billing_changes r WHERE r.id = NEW.record_id AND r.account_id = NEW.account_id AND r.kind = 'refund'",
            'approve' => "SELECT 1 FROM billing_change_reviews r JOIN billing_changes c ON c.id = r.change_id WHERE r.id = NEW.record_id AND c.account_id = NEW.account_id AND r.decision = 'approved'",
            'reject' => "SELECT 1 FROM billing_change_reviews r JOIN billing_changes c ON c.id = r.change_id WHERE r.id = NEW.record_id AND c.account_id = NEW.account_id AND r.decision = 'rejected'",
        ];
        $valid = [];
        foreach ($bindings as $action => $query) {
            $valid[] = "(NEW.action = '{$action}' AND EXISTS ({$query}))";
        }
        $this->guard('billing_events', 'billing_event_binding', 'NOT ('.implode(' OR ', $valid).')');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('billing_change_reviews', 'payout_method')
            && DB::table('billing_change_reviews')->whereNotNull('payout_method')->exists()) {
            throw new RuntimeException('Cannot remove recorded physical-refund details. Preserve the financial records before this rollback.');
        }
        foreach (['billing_payout_confirmation', 'billing_event_sequence', 'billing_event_binding'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        if (Schema::hasColumn('billing_change_reviews', 'payout_key')) {
            Schema::table('billing_change_reviews', function (Blueprint $table): void {
                $table->dropUnique(['payout_key']);
                $table->dropColumn('payout_key');
                $table->dropColumn(['payout_method','payee_name','payout_details']);
            });
        }
    }

    private function guard(string $table, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing workflow integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Billing workflow integrity violation'); END");
        }
    }
};
