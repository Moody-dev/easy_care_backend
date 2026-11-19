<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['hospital_medication_units', 'allocation_rates', 'admission_exit_types', 'accommodation_assessments'];

    public function up(): void
    {
        Schema::create('hospital_medication_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->unique()->constrained('hospital_price_imports')->restrictOnDelete();
            $table->string('basis', 32);
            $table->text('confirmation');
            $table->dateTime('recorded_at', 6);
        });
        Schema::create('allocation_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->unique()->constrained('bed_allocations')->restrictOnDelete();
            $table->unsignedBigInteger('daily_rate_kobo')->nullable();
            $table->string('source', 16);
            $table->unsignedInteger('source_version')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
        });
        Schema::create('admission_exit_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_id')->unique()->constrained()->restrictOnDelete();
            $table->string('exit_type', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
        });
        Schema::create('accommodation_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_id')->unique()->constrained()->restrictOnDelete();
            $table->char('source_key', 64)->unique();
            $table->string('status', 24);
            $table->unsignedBigInteger('total_kobo')->nullable();
            $table->json('snapshot');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
        });
        foreach (self::TABLES as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard($table, $event, $table.'_retain_'.strtolower($event), '1 = 1');
            }
        }
        $this->guard('hospital_medication_units', 'INSERT', 'medication_unit_basis', "NEW.basis <> 'per_sellable_item'");
        $this->guard('allocation_rates', 'INSERT', 'allocation_rate_binding', "(NEW.daily_rate_kobo IS NOT NULL AND NEW.daily_rate_kobo NOT BETWEEN 0 AND 99999999999999)
            OR NEW.source NOT IN ('bed','ward','missing') OR NOT EXISTS (SELECT 1 FROM bed_allocations a WHERE a.id = NEW.allocation_id
                AND a.assigned_by = NEW.actor_id AND a.assigned_at = NEW.recorded_at)");
        $this->guard('admission_exit_types', 'INSERT', 'admission_exit_binding', "NEW.exit_type NOT IN ('discharge','referral','dama')
            OR NOT EXISTS (SELECT 1 FROM admissions a WHERE a.id = NEW.admission_id AND a.status = 'discharge_authorized'
                AND a.authorized_by = NEW.actor_id AND a.authorized_at = NEW.recorded_at)");
        $this->guard('accommodation_assessments', 'INSERT', 'accommodation_exit_binding', "NEW.status NOT IN ('ready','requires_review')
            OR (NEW.status = 'ready' AND (NEW.total_kobo IS NULL OR NEW.total_kobo NOT BETWEEN 0 AND 99999999999))
            OR (NEW.status = 'requires_review' AND NEW.total_kobo IS NOT NULL)
            OR NOT EXISTS (SELECT 1 FROM admissions a WHERE a.id = NEW.admission_id AND a.status = 'discharged'
                AND a.discharged_by = NEW.actor_id AND a.discharged_at = NEW.recorded_at)");
        $this->priceGuard(true);
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained medication-unit and accommodation billing facts cannot be removed by rollback.');
            }
        }
        $this->priceGuard(false);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function priceGuard(bool $accommodation): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS billing_item_price');
        $ordinary = "((NEW.service_price_id IS NOT NULL AND NEW.drug_price_id IS NULL
            AND EXISTS (SELECT 1 FROM service_prices p WHERE p.id = NEW.service_price_id AND p.amount_micros = NEW.unit_price_micros AND p.currency = 'NGN'))
            OR (NEW.service_price_id IS NULL AND NEW.drug_price_id IS NOT NULL AND EXISTS (SELECT 1 FROM drug_prices p WHERE p.id = NEW.drug_price_id
                AND p.kind = 'selling' AND p.currency = 'NGN' AND p.amount_micros = NEW.unit_price_micros)))";
        $valid = "((NEW.tariff_id IS NULL AND {$ordinary}) OR (NEW.tariff_id IS NOT NULL AND NEW.service_price_id IS NULL AND NEW.drug_price_id IS NULL
            AND EXISTS (SELECT 1 FROM hmo_tariffs t WHERE t.id = NEW.tariff_id AND t.amount_micros = NEW.unit_price_micros)))";
        if ($accommodation) {
            $valid = "(NEW.source_type <> 'accommodation' AND {$valid})";
            $valid .= " OR (NEW.source_type = 'correction' AND NEW.service_price_id IS NULL AND NEW.drug_price_id IS NULL AND NEW.tariff_id IS NULL
                AND EXISTS (SELECT 1 FROM billing_invoice_items original
                    JOIN billing_changes change_record ON change_record.item_id = original.id
                    JOIN billing_change_reviews review_record ON review_record.change_id = change_record.id AND review_record.decision = 'approved'
                    JOIN billing_invoices old_invoice ON old_invoice.id = original.invoice_id
                    JOIN billing_invoices new_invoice ON new_invoice.id = NEW.invoice_id AND new_invoice.account_id = old_invoice.account_id
                        AND new_invoice.encounter_id = old_invoice.encounter_id
                    WHERE original.id = NEW.corrects_item_id AND original.source_type IN ('accommodation','correction')
                        AND original.service_price_id IS NULL AND original.drug_price_id IS NULL AND original.tariff_id IS NULL
                        AND original.unit_price_micros = NEW.unit_price_micros AND change_record.id = NEW.source_id
                        AND change_record.replacement_quantity_micros = NEW.quantity_micros
                        AND review_record.actor_id = new_invoice.actor_id AND review_record.recorded_at = new_invoice.recorded_at))";
            $valid .= " OR (NEW.source_type = 'accommodation' AND NEW.service_price_id IS NULL AND NEW.drug_price_id IS NULL AND NEW.tariff_id IS NULL
                AND NEW.quantity_micros = 1000000 AND EXISTS (SELECT 1 FROM accommodation_assessments s
                    JOIN admissions a ON a.id = s.admission_id JOIN billing_invoices i ON i.encounter_id = a.encounter_id
                    JOIN billing_accounts c ON c.id = i.account_id AND c.patient_id = a.patient_id
                    WHERE s.id = NEW.source_id AND s.source_key = NEW.source_key AND s.status = 'ready' AND i.id = NEW.invoice_id
                        AND NEW.amount_kobo = s.total_kobo AND NEW.unit_price_micros = s.total_kobo * 10000))";
        }
        $this->guard('billing_invoice_items', 'INSERT', 'billing_item_price', "({$valid}) IS NOT TRUE");
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        DB::unprepared(DB::getDriverName() === 'mysql'
            ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retained pricing integrity violation'; END IF; END"
            : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Retained pricing integrity violation'); END");
    }
};
