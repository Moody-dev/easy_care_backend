<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['service_prices', 'billing_accounts', 'billing_performed_services', 'billing_invoices',
        'billing_invoice_items', 'billing_invoice_posts', 'billing_receipts', 'billing_allocations', 'billing_changes',
        'billing_change_reviews', 'billing_allocation_releases', 'billing_events'];

    public function up(): void
    {
        DB::unprepared('DROP TRIGGER services_initial');
        $this->serviceGuard(true);
        Schema::create('service_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('amount_micros');
            $table->char('currency', 3)->default('NGN');
            $table->dateTime('effective_at', 6);
            $table->boolean('is_active');
            $table->text('approval_reference');
            $this->signature($table);
            $table->unique(['service_id', 'version']);
        });
        Schema::create('billing_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->restrictOnDelete();
            $table->char('currency', 3)->default('NGN');
            $this->signature($table);
        });
        Schema::create('billing_performed_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('quantity_micros');
            $table->dateTime('occurred_at', 6);
            $table->string('reference', 128);
            $table->char('reference_key', 64);
            $this->signature($table);
            $table->unique(['encounter_id', 'reference_key']);
        });
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->char('currency', 3)->default('NGN');
            $table->unsignedSmallInteger('item_count');
            $table->unsignedBigInteger('total_kobo');
            $this->signature($table);
        });
        Schema::create('billing_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->string('source_type', 24);
            $table->unsignedBigInteger('source_id');
            $table->char('source_key', 64)->unique();
            $table->foreignId('service_price_id')->nullable()->constrained('service_prices')->restrictOnDelete();
            $table->foreignId('drug_price_id')->nullable()->constrained('drug_prices')->restrictOnDelete();
            $table->foreignId('corrects_item_id')->nullable()->constrained('billing_invoice_items')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_micros');
            $table->unsignedBigInteger('unit_price_micros');
            $table->unsignedBigInteger('amount_kobo');
            $table->string('description', 191);
            $table->json('snapshot');
            $this->signature($table);
        });
        Schema::create('billing_invoice_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->unique()->constrained('billing_invoices')->restrictOnDelete();
            $this->signature($table);
        });
        Schema::create('billing_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount_kobo');
            $table->char('currency', 3)->default('NGN');
            $table->string('method', 24);
            $table->string('reference', 128)->nullable();
            $table->char('reference_key', 64)->nullable()->unique();
            $table->string('payer_name', 191);
            $table->string('payer_phone', 32)->nullable();
            $table->dateTime('paid_at', 6);
            $table->json('transaction_details');
            $table->boolean('externally_confirmed');
            $this->signature($table);
        });
        Schema::create('billing_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained('billing_receipts')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('billing_invoices')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $this->signature($table);
        });
        Schema::create('billing_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $table->string('kind', 16);
            $table->foreignId('item_id')->nullable()->constrained('billing_invoice_items')->restrictOnDelete();
            $table->foreignId('receipt_id')->nullable()->constrained('billing_receipts')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedBigInteger('replacement_quantity_micros')->nullable();
            $this->signature($table);
        });
        Schema::create('billing_change_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('change_id')->unique()->constrained('billing_changes')->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('payout_reference', 128)->nullable();
            $table->dateTime('payout_at', 6)->nullable();
            $table->boolean('payout_confirmed')->default(false);
            $this->signature($table);
        });
        Schema::create('billing_allocation_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->constrained('billing_allocations')->restrictOnDelete();
            $table->foreignId('review_id')->constrained('billing_change_reviews')->restrictOnDelete();
            $table->unsignedBigInteger('amount_kobo');
            $this->signature($table);
        });
        Schema::create('billing_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 32);
            $table->unsignedBigInteger('record_id');
            $this->signature($table);
            $table->unique(['account_id', 'version']);
            $table->unique(['account_id', 'action', 'record_id']);
        });
        foreach (self::TABLES as $table) {
            foreach (['update', 'delete'] as $event) {
                $this->guard($table, $event, $table.'_retain_'.$event, '1 = 1');
            }
            $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
            $this->guard($table, 'insert', $table.'_reason', "({$length}(trim(NEW.reason)) BETWEEN 5 AND 2000) IS NOT TRUE");
            if (DB::getDriverName() === 'sqlite') {
                $this->guard($table, 'insert', $table.'_time', 'julianday(NEW.recorded_at) IS NULL');
            }
        }
        foreach (['service_prices' => 'amount_micros', 'billing_performed_services' => 'quantity_micros', 'billing_invoice_items' => 'quantity_micros'] as $table => $column) {
            $this->range($table, $column, $table === 'service_prices' ? 0 : 1, 999999999999999);
        }
        foreach (['billing_invoices' => 'total_kobo', 'billing_invoice_items' => 'amount_kobo', 'billing_receipts' => 'amount_kobo',
            'billing_allocations' => 'amount_kobo', 'billing_changes' => 'amount_kobo', 'billing_allocation_releases' => 'amount_kobo'] as $table => $column) {
            $this->range($table, $column, in_array($table, ['billing_invoices','billing_invoice_items'], true) ? 0 : 1, 99999999999999);
        }
        foreach (['service_prices', 'billing_accounts', 'billing_invoices', 'billing_receipts'] as $table) {
            $this->guard($table, 'insert', $table.'_currency', "NEW.currency <> 'NGN'");
        }
        $this->guard('service_prices', 'insert', 'billing_price_version', "NEW.version < 1 OR NEW.effective_at < NEW.recorded_at
            OR (NEW.version > 1 AND NOT EXISTS (SELECT 1 FROM service_prices p WHERE p.service_id = NEW.service_id
                AND p.version = NEW.version - 1 AND p.effective_at <= NEW.effective_at AND p.recorded_at <= NEW.recorded_at))");
        foreach (['billing_invoices', 'billing_performed_services'] as $table) {
            $this->guard($table, 'insert', $table.'_patient', 'NOT EXISTS (SELECT 1 FROM billing_accounts a JOIN encounters e ON e.patient_id = a.patient_id WHERE a.id = NEW.account_id AND e.id = NEW.encounter_id)');
        }
        $this->guard('billing_performed_services', 'insert', 'billing_performed_time', 'NEW.occurred_at > NEW.recorded_at');
        $this->guard('billing_invoice_items', 'insert', 'billing_item_sealed', 'EXISTS (SELECT 1 FROM billing_invoice_posts p WHERE p.invoice_id = NEW.invoice_id)');
        $this->guard('billing_invoice_items', 'insert', 'billing_item_price', "((NEW.service_price_id IS NOT NULL AND NEW.drug_price_id IS NULL
            AND EXISTS (SELECT 1 FROM service_prices p WHERE p.id = NEW.service_price_id AND p.amount_micros = NEW.unit_price_micros AND p.currency = 'NGN'))
            OR (NEW.service_price_id IS NULL AND NEW.drug_price_id IS NOT NULL AND EXISTS (SELECT 1 FROM drug_prices p WHERE p.id = NEW.drug_price_id
                AND p.kind = 'selling' AND p.currency = 'NGN' AND p.amount_micros = NEW.unit_price_micros))) IS NOT TRUE");
        $this->guard('billing_invoice_posts', 'insert', 'billing_invoice_seal', 'NOT EXISTS (SELECT 1 FROM billing_invoices i WHERE i.id = NEW.invoice_id
            AND i.item_count = (SELECT COUNT(*) FROM billing_invoice_items l WHERE l.invoice_id = i.id)
            AND i.total_kobo = (SELECT SUM(l.amount_kobo) FROM billing_invoice_items l WHERE l.invoice_id = i.id) AND i.actor_id = NEW.actor_id)');
        $this->guard('billing_receipts', 'insert', 'billing_receipt_method', "NEW.kind NOT IN ('deposit','payment') OR NEW.method NOT IN ('cash','bank_transfer','pos','other')
            OR (NEW.method <> 'cash' AND (NEW.reference IS NULL OR NEW.reference_key IS NULL OR NEW.externally_confirmed <> 1))
            OR (NEW.method = 'cash' AND NEW.externally_confirmed <> 0) OR NEW.paid_at > NEW.recorded_at");
        $this->guard('billing_allocations', 'insert', 'billing_allocation_patient', 'NOT EXISTS (SELECT 1 FROM billing_receipts r JOIN billing_invoices i ON i.account_id = r.account_id
            JOIN billing_invoice_posts p ON p.invoice_id = i.id WHERE r.id = NEW.receipt_id AND i.id = NEW.invoice_id)');
        $this->guard('billing_changes', 'insert', 'billing_change_target', "((NEW.kind = 'credit' AND NEW.receipt_id IS NULL AND NEW.item_id IS NOT NULL
            AND EXISTS (SELECT 1 FROM billing_invoice_items l JOIN billing_invoices i ON i.id = l.invoice_id WHERE l.id = NEW.item_id AND i.account_id = NEW.account_id))
            OR (NEW.kind = 'refund' AND NEW.item_id IS NULL AND NEW.replacement_quantity_micros IS NULL AND NEW.receipt_id IS NOT NULL
            AND EXISTS (SELECT 1 FROM billing_receipts r WHERE r.id = NEW.receipt_id AND r.account_id = NEW.account_id))) IS NOT TRUE");
        $this->guard('billing_change_reviews', 'insert', 'billing_change_reviewer', "NEW.decision NOT IN ('approved','rejected')
            OR NOT EXISTS (SELECT 1 FROM billing_changes c WHERE c.id = NEW.change_id AND c.actor_id <> NEW.actor_id AND c.recorded_at <= NEW.recorded_at)");
        $this->guard('billing_allocation_releases', 'insert', 'billing_release_account', "NOT EXISTS (SELECT 1 FROM billing_allocations a JOIN billing_invoices i ON i.id = a.invoice_id
            JOIN billing_change_reviews v ON v.id = NEW.review_id JOIN billing_changes c ON c.id = v.change_id JOIN billing_invoice_items l ON l.id = c.item_id
            WHERE a.id = NEW.allocation_id AND v.decision = 'approved' AND c.kind = 'credit' AND l.invoice_id = i.id AND c.account_id = i.account_id)");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared('DROP TRIGGER services_initial');
        $this->serviceGuard(false);
    }

    private function signature(Blueprint $table): void
    {
        $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
        $table->dateTime('recorded_at', 6);
        $table->text('reason');
    }

    private function range(string $table, string $column, int $minimum, int $maximum): void
    {
        $this->guard($table, 'insert', $table.'_'.$column, "NEW.{$column} NOT BETWEEN {$minimum} AND {$maximum}"
            .(DB::getDriverName() === 'sqlite' ? " OR typeof(NEW.{$column}) <> 'integer'" : ''));
    }

    private function serviceGuard(bool $billing): void
    {
        $categories = $billing ? "'laboratory','radiology','consultation','procedure','ward','registration','other'" : "'laboratory','radiology'";
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->guard('services', 'insert', 'services_initial', "(NEW.category IN ({$categories}) AND NEW.is_active = 1
            AND {$length}(trim(NEW.code)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191) IS NOT TRUE");
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Billing integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Billing integrity violation'); END");
        }
    }
};
