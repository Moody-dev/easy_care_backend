<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['hmo_providers','hmo_plans','hmo_catalog_revisions','hmo_tariff_imports','hmo_tariffs',
        'hmo_coverages','hmo_preauthorizations','hmo_revocations','hmo_invoice_links','hmo_invoice_shares','hmo_claims',
        'hmo_submissions','hmo_decisions','hmo_decision_lines','hmo_remittances','hmo_allocations','hmo_allocation_lines','hmo_reviews','hmo_reference_bindings'];

    public function up(): void
    {
        Schema::create('hmo_providers', function (Blueprint $t): void {
            $t->id(); $t->string('code',64)->unique(); $t->string('name',191); $this->signature($t);
        });
        Schema::create('hmo_plans', function (Blueprint $t): void {
            $t->id(); $t->foreignId('provider_id')->constrained('hmo_providers')->restrictOnDelete();
            $t->string('code',64); $t->string('name',191); $this->signature($t); $t->unique(['provider_id','code']);
        });
        Schema::create('hmo_catalog_revisions', function (Blueprint $t): void {
            $t->id(); $t->string('kind',16); $t->unsignedBigInteger('record_id'); $t->unsignedInteger('version');
            $t->boolean('is_active'); $t->boolean('preauth_required'); $t->json('details'); $this->signature($t);
            $t->unique(['kind','record_id','version']);
        });
        Schema::create('hmo_tariff_imports', function (Blueprint $t): void {
            $t->id(); $t->foreignId('plan_id')->constrained('hmo_plans')->restrictOnDelete();
            $t->unsignedInteger('expected_version'); $t->dateTime('proposed_effective_at',6);
            $t->string('filename',128); $t->char('sha256',64); $t->unsignedInteger('byte_size');
            $t->unsignedSmallInteger('row_count'); $t->longText('csv_ciphertext'); $t->text('approval_reference'); $this->signature($t);
        });
        Schema::create('hmo_tariffs', function (Blueprint $t): void {
            $t->id(); $t->foreignId('import_id')->constrained('hmo_tariff_imports')->restrictOnDelete();
            $t->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $t->foreignId('drug_id')->nullable()->constrained('drugs')->restrictOnDelete();
            $t->string('subject_key',80); $t->unsignedBigInteger('amount_micros'); $t->boolean('preauth_required');
            $t->json('snapshot'); $this->signature($t); $t->unique(['import_id','subject_key']);
        });
        Schema::create('hmo_coverages', function (Blueprint $t): void {
            $t->id(); $t->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $t->foreignId('plan_id')->constrained('hmo_plans')->restrictOnDelete(); $t->string('member_number',128);
            $t->date('valid_from'); $t->date('valid_until'); $t->string('group_number',128)->nullable();
            $t->string('employer_name',191)->nullable(); $t->text('verification_reference'); $t->json('snapshot'); $this->signature($t);
            $t->index(['account_id','plan_id','member_number','valid_from'],'hmo_coverage_identity');
        });
        Schema::create('hmo_preauthorizations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $t->foreignId('coverage_id')->constrained('hmo_coverages')->restrictOnDelete();
            $t->foreignId('encounter_id')->constrained('encounters')->restrictOnDelete();
            $t->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $t->foreignId('drug_id')->nullable()->constrained('drugs')->restrictOnDelete();
            $t->string('status',16); $t->unsignedBigInteger('limit_kobo'); $t->string('reference',128);
            $t->dateTime('approved_at',6); $t->dateTime('valid_from',6); $t->dateTime('valid_until',6); $this->signature($t);
        });
        Schema::create('hmo_revocations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $t->string('kind',16); $t->unsignedBigInteger('record_id'); $this->signature($t); $t->unique(['kind','record_id']);
        });
        Schema::create('hmo_invoice_links', function (Blueprint $t): void {
            $t->id(); $t->foreignId('invoice_id')->unique()->constrained('billing_invoices')->restrictOnDelete();
            $t->foreignId('coverage_id')->constrained('hmo_coverages')->restrictOnDelete();
            $t->foreignId('import_id')->constrained('hmo_tariff_imports')->restrictOnDelete(); $t->json('snapshot'); $this->signature($t);
        });
        Schema::create('hmo_invoice_shares', function (Blueprint $t): void {
            $t->id(); $t->foreignId('link_id')->constrained('hmo_invoice_links')->restrictOnDelete();
            $t->foreignId('item_id')->unique()->constrained('billing_invoice_items')->restrictOnDelete();
            $t->foreignId('tariff_id')->constrained('hmo_tariffs')->restrictOnDelete();
            $t->foreignId('preauth_id')->nullable()->constrained('hmo_preauthorizations')->restrictOnDelete();
            $t->unsignedBigInteger('patient_kobo'); $t->unsignedBigInteger('insurer_kobo'); $t->text('benefit_reference'); $this->signature($t);
        });
        Schema::create('hmo_claims', function (Blueprint $t): void {
            $t->id(); $t->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $t->foreignId('invoice_id')->unique()->constrained('billing_invoices')->restrictOnDelete(); $this->signature($t);
        });
        Schema::create('hmo_submissions', function (Blueprint $t): void {
            $t->id(); $t->foreignId('claim_id')->constrained('hmo_claims')->restrictOnDelete();
            $t->unsignedInteger('sequence'); $t->string('reference',128); $t->dateTime('submitted_at',6); $t->json('snapshot');
            $this->signature($t); $t->unique(['claim_id','sequence']);
        });
        Schema::create('hmo_decisions', function (Blueprint $t): void {
            $t->id(); $t->foreignId('claim_id')->constrained('hmo_claims')->restrictOnDelete();
            $t->foreignId('submission_id')->constrained('hmo_submissions')->restrictOnDelete();
            $t->string('reference',128); $t->dateTime('occurred_at',6); $this->signature($t);
        });
        Schema::create('hmo_decision_lines', function (Blueprint $t): void {
            $t->id(); $t->foreignId('decision_id')->constrained('hmo_decisions')->restrictOnDelete();
            $t->foreignId('item_id')->constrained('billing_invoice_items')->restrictOnDelete(); $t->unsignedBigInteger('approved_kobo');
            $this->signature($t); $t->unique(['decision_id','item_id']);
        });
        Schema::create('hmo_remittances', function (Blueprint $t): void {
            $t->id(); $t->foreignId('provider_id')->constrained('hmo_providers')->restrictOnDelete(); $t->unsignedBigInteger('amount_kobo');
            $t->string('reference',128); $t->char('reference_key',64); $t->string('method',24); $t->string('payer_name',191);
            $t->dateTime('received_at',6); $t->json('transaction_details'); $this->signature($t);
        });
        Schema::create('hmo_allocations', function (Blueprint $t): void {
            $t->id(); $t->foreignId('account_id')->constrained('billing_accounts')->restrictOnDelete();
            $t->foreignId('claim_id')->constrained('hmo_claims')->restrictOnDelete();
            $t->foreignId('remittance_id')->constrained('hmo_remittances')->restrictOnDelete();
            $t->unsignedBigInteger('amount_kobo'); $t->string('reference',128); $t->char('reference_key',64); $this->signature($t);
        });
        Schema::create('hmo_allocation_lines', function (Blueprint $t): void {
            $t->id(); $t->foreignId('allocation_id')->constrained('hmo_allocations')->restrictOnDelete();
            $t->foreignId('item_id')->constrained('billing_invoice_items')->restrictOnDelete();
            $t->unsignedBigInteger('amount_kobo'); $this->signature($t); $t->unique(['allocation_id','item_id']);
        });
        Schema::create('hmo_reviews', function (Blueprint $t): void {
            $t->id(); $t->string('kind',16); $t->unsignedBigInteger('record_id');
            $t->foreignId('provider_id')->constrained('hmo_providers')->restrictOnDelete();
            $t->foreignId('account_id')->nullable()->constrained('billing_accounts')->restrictOnDelete();
            $t->string('outcome',16); $t->unsignedInteger('published_version')->nullable(); $t->dateTime('effective_at',6)->nullable();
            $this->signature($t); $t->unique(['kind','record_id']);
        });
        Schema::create('hmo_reference_bindings', function (Blueprint $t): void {
            $t->id(); $t->foreignId('provider_id')->constrained('hmo_providers')->restrictOnDelete();
            $t->string('kind',24); $t->char('reference_key',64); $t->unsignedBigInteger('owner_id'); $this->signature($t);
            $t->unique(['provider_id','kind','reference_key'],'hmo_external_reference_unique');
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE billing_invoice_items ADD COLUMN tariff_id INTEGER NULL REFERENCES hmo_tariffs(id) ON DELETE RESTRICT');
        } else {
            Schema::table('billing_invoice_items', function (Blueprint $t): void {
                $t->foreignId('tariff_id')->nullable()->constrained('hmo_tariffs')->restrictOnDelete();
            });
        }
        foreach (self::TABLES as $table) {
            foreach (['update','delete'] as $event) { $this->guard($table,$event,$table.'_retain_'.$event,'1 = 1'); }
            $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
            $this->guard($table,'insert',$table.'_signature',"({$length}(trim(NEW.reason)) BETWEEN 5 AND 2000) IS NOT TRUE");
        }
        foreach (['hmo_preauthorizations'=>['limit_kobo'], 'hmo_invoice_shares'=>['patient_kobo','insurer_kobo'], 'hmo_decision_lines'=>['approved_kobo'],
            'hmo_remittances'=>['amount_kobo'], 'hmo_allocations'=>['amount_kobo'], 'hmo_allocation_lines'=>['amount_kobo']] as $table=>$fields) {
            foreach ($fields as $field) {
                $minimum = in_array($table,['hmo_remittances','hmo_allocations','hmo_allocation_lines'],true) ? 1 : 0;
                $this->guard($table,'insert',$table.'_'.$field,"NEW.{$field} NOT BETWEEN {$minimum} AND 99999999999999"
                    .(DB::getDriverName()==='sqlite' ? " OR typeof(NEW.{$field}) <> 'integer'" : ''));
            }
        }
        $this->guard('hmo_catalog_revisions','insert','hmo_catalog_scope', "NEW.version < 1 OR NEW.is_active NOT IN (0,1) OR NEW.preauth_required NOT IN (0,1)
            OR NOT ((NEW.kind='provider' AND EXISTS (SELECT 1 FROM hmo_providers p WHERE p.id=NEW.record_id))
                OR (NEW.kind='plan' AND EXISTS (SELECT 1 FROM hmo_plans p WHERE p.id=NEW.record_id)))");
        $this->guard('hmo_tariffs','insert','hmo_tariff_subject', '((NEW.service_id IS NULL) = (NEW.drug_id IS NULL))
            OR NEW.amount_micros NOT BETWEEN 0 AND 999999999999999 OR NEW.preauth_required NOT IN (0,1)');
        $this->guard('hmo_revocations','insert','hmo_revocation_scope', "NOT ((NEW.kind='coverage' AND EXISTS (
            SELECT 1 FROM hmo_coverages c WHERE c.id=NEW.record_id AND c.account_id=NEW.account_id)) OR (NEW.kind='preauth' AND EXISTS (
            SELECT 1 FROM hmo_preauthorizations p WHERE p.id=NEW.record_id AND p.account_id=NEW.account_id)))");
        $this->guard('hmo_coverages','insert','hmo_coverage_dates','NEW.valid_until < NEW.valid_from');
        $this->guard('hmo_preauthorizations','insert','hmo_preauth_scope',"NEW.valid_until < NEW.valid_from OR NEW.approved_at > NEW.recorded_at
            OR NEW.status NOT IN ('approved','denied') OR (NEW.status='denied' AND NEW.limit_kobo<>0)
            OR ((NEW.service_id IS NULL) = (NEW.drug_id IS NULL)) OR NOT EXISTS (
            SELECT 1 FROM hmo_coverages c JOIN billing_accounts a ON a.id=c.account_id JOIN encounters e ON e.patient_id=a.patient_id
            WHERE c.id=NEW.coverage_id AND c.account_id=NEW.account_id AND e.id=NEW.encounter_id)");
        $this->guard('hmo_invoice_links','insert','hmo_invoice_coverage',"NOT EXISTS (SELECT 1 FROM hmo_coverages c
            JOIN billing_invoices i ON i.account_id=c.account_id JOIN hmo_tariff_imports t ON t.plan_id=c.plan_id
            WHERE c.id=NEW.coverage_id AND i.id=NEW.invoice_id AND t.id=NEW.import_id)");
        $this->guard('hmo_invoice_shares','insert','hmo_invoice_split',"NOT EXISTS (SELECT 1 FROM hmo_invoice_links l
            JOIN billing_invoice_items i ON i.invoice_id=l.invoice_id JOIN hmo_tariffs t ON t.import_id=l.import_id
            WHERE l.id=NEW.link_id AND i.id=NEW.item_id AND t.id=NEW.tariff_id AND i.tariff_id=t.id
            AND NEW.patient_kobo+NEW.insurer_kobo=i.amount_kobo)
            OR (NEW.preauth_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM hmo_preauthorizations p JOIN hmo_invoice_links l ON l.coverage_id=p.coverage_id
                WHERE p.id=NEW.preauth_id AND l.id=NEW.link_id))");
        $this->guard('hmo_claims','insert','hmo_claim_invoice',"NOT EXISTS (SELECT 1 FROM billing_invoices i JOIN hmo_invoice_links l ON l.invoice_id=i.id
            WHERE i.id=NEW.invoice_id AND i.account_id=NEW.account_id)");
        $this->guard('hmo_decisions','insert','hmo_decision_submission','NOT EXISTS (SELECT 1 FROM hmo_submissions s WHERE s.id=NEW.submission_id AND s.claim_id=NEW.claim_id AND s.submitted_at<=NEW.occurred_at) OR NEW.occurred_at>NEW.recorded_at');
        $this->guard('hmo_decision_lines','insert','hmo_decision_line_scope','NOT EXISTS (SELECT 1 FROM hmo_decisions d JOIN hmo_claims c ON c.id=d.claim_id
            JOIN billing_invoice_items i ON i.invoice_id=c.invoice_id JOIN hmo_invoice_shares s ON s.item_id=i.id
            WHERE d.id=NEW.decision_id AND i.id=NEW.item_id AND NEW.approved_kobo<=s.insurer_kobo)');
        $this->guard('hmo_allocations','insert','hmo_allocation_scope','NOT EXISTS (SELECT 1 FROM hmo_claims c JOIN hmo_invoice_links l ON l.invoice_id=c.invoice_id
            JOIN hmo_coverages v ON v.id=l.coverage_id JOIN hmo_plans p ON p.id=v.plan_id JOIN hmo_remittances r ON r.provider_id=p.provider_id
            WHERE c.id=NEW.claim_id AND c.account_id=NEW.account_id AND r.id=NEW.remittance_id)');
        $this->guard('hmo_allocation_lines','insert','hmo_allocation_line_scope','NOT EXISTS (SELECT 1 FROM hmo_allocations a JOIN hmo_claims c ON c.id=a.claim_id
            JOIN billing_invoice_items i ON i.invoice_id=c.invoice_id WHERE a.id=NEW.allocation_id AND i.id=NEW.item_id)');
        $reviewSources = ['tariff'=>'hmo_tariff_imports','decision'=>'hmo_decisions','remittance'=>'hmo_remittances','allocation'=>'hmo_allocations'];
        $valid = [];
        foreach ($reviewSources as $kind=>$table) { $valid[] = "(NEW.kind='{$kind}' AND EXISTS (SELECT 1 FROM {$table} r WHERE r.id=NEW.record_id AND r.actor_id<>NEW.actor_id AND r.recorded_at<=NEW.recorded_at))"; }
        $this->guard('hmo_reviews','insert','hmo_review_independent',"NEW.outcome NOT IN ('accepted','rejected') OR NOT (".implode(' OR ',$valid).')');
        $this->guard('hmo_reviews','insert','hmo_review_scope', "(
            (NEW.kind='tariff' AND NEW.account_id IS NULL AND EXISTS (SELECT 1 FROM hmo_tariff_imports i JOIN hmo_plans p ON p.id=i.plan_id
                WHERE i.id=NEW.record_id AND p.provider_id=NEW.provider_id)
                AND ((NEW.outcome='accepted' AND NEW.published_version>0 AND NEW.effective_at>=NEW.recorded_at)
                    OR (NEW.outcome='rejected' AND NEW.published_version IS NULL AND NEW.effective_at IS NULL)))
            OR (NEW.kind='remittance' AND NEW.account_id IS NULL AND NEW.published_version IS NULL AND NEW.effective_at IS NULL
                AND EXISTS (SELECT 1 FROM hmo_remittances r WHERE r.id=NEW.record_id AND r.provider_id=NEW.provider_id))
            OR (NEW.kind='decision' AND NEW.published_version IS NULL AND NEW.effective_at IS NULL AND EXISTS (
                SELECT 1 FROM hmo_decisions d JOIN hmo_claims c ON c.id=d.claim_id JOIN hmo_invoice_links l ON l.invoice_id=c.invoice_id
                JOIN hmo_coverages v ON v.id=l.coverage_id JOIN hmo_plans p ON p.id=v.plan_id
                WHERE d.id=NEW.record_id AND c.account_id=NEW.account_id AND p.provider_id=NEW.provider_id))
            OR (NEW.kind='allocation' AND NEW.published_version IS NULL AND NEW.effective_at IS NULL AND EXISTS (
                SELECT 1 FROM hmo_allocations a JOIN hmo_remittances r ON r.id=a.remittance_id
                WHERE a.id=NEW.record_id AND a.account_id=NEW.account_id AND r.provider_id=NEW.provider_id))) IS NOT TRUE");
        $this->priceGuard(true);
        $this->eventGuard(true);
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot remove retained HMO records. Preserve these records before rollback.');
            }
        }
        $this->eventGuard(false);
        DB::unprepared('DROP TRIGGER IF EXISTS hmo_invoice_split');
        DB::unprepared('DROP TRIGGER IF EXISTS billing_item_price');
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE billing_invoice_items DROP COLUMN tariff_id');
        } else {
            Schema::table('billing_invoice_items', function (Blueprint $t): void { $t->dropForeign(['tariff_id']); $t->dropColumn('tariff_id'); });
        }
        $this->priceGuard(false);
        foreach (array_reverse(self::TABLES) as $table) { Schema::dropIfExists($table); }
    }

    private function priceGuard(bool $hmo): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS billing_item_price');
        $ordinary = "((NEW.service_price_id IS NOT NULL AND NEW.drug_price_id IS NULL
            AND EXISTS (SELECT 1 FROM service_prices p WHERE p.id=NEW.service_price_id AND p.amount_micros=NEW.unit_price_micros AND p.currency='NGN'))
            OR (NEW.service_price_id IS NULL AND NEW.drug_price_id IS NOT NULL AND EXISTS (SELECT 1 FROM drug_prices p WHERE p.id=NEW.drug_price_id
                AND p.kind='selling' AND p.currency='NGN' AND p.amount_micros=NEW.unit_price_micros)))";
        $valid = $hmo ? "((NEW.tariff_id IS NULL AND {$ordinary}) OR (NEW.tariff_id IS NOT NULL AND NEW.service_price_id IS NULL AND NEW.drug_price_id IS NULL
            AND EXISTS (SELECT 1 FROM hmo_tariffs t WHERE t.id=NEW.tariff_id AND t.amount_micros=NEW.unit_price_micros)))" : $ordinary;
        $this->guard('billing_invoice_items','insert','billing_item_price',"({$valid}) IS NOT TRUE");
    }

    private function eventGuard(bool $hmo): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS billing_event_binding');
        $bindings = [
            'performed'=>"SELECT 1 FROM billing_performed_services r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id",
            'invoice'=>"SELECT 1 FROM billing_invoices r JOIN billing_invoice_posts p ON p.invoice_id=r.id WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id",
            'receipt'=>"SELECT 1 FROM billing_receipts r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id",
            'allocate'=>"SELECT 1 FROM billing_allocations r JOIN billing_receipts p ON p.id=r.receipt_id WHERE r.id=NEW.record_id AND p.account_id=NEW.account_id",
            'credit-request'=>"SELECT 1 FROM billing_changes r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id AND r.kind='credit'",
            'refund-request'=>"SELECT 1 FROM billing_changes r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id AND r.kind='refund'",
            'approve'=>"SELECT 1 FROM billing_change_reviews r JOIN billing_changes c ON c.id=r.change_id WHERE r.id=NEW.record_id AND c.account_id=NEW.account_id AND r.decision='approved'",
            'reject'=>"SELECT 1 FROM billing_change_reviews r JOIN billing_changes c ON c.id=r.change_id WHERE r.id=NEW.record_id AND c.account_id=NEW.account_id AND r.decision='rejected'",
        ];
        if ($hmo) {
            foreach (['coverage-create'=>'hmo_coverages','preauth-create'=>'hmo_preauthorizations','coverage-revoke'=>'hmo_revocations','preauth-revoke'=>'hmo_revocations',
                'claim-submit'=>'hmo_claims','claim-resubmit'=>'hmo_claims','allocation-record'=>'hmo_allocations'] as $action=>$table) {
                $bindings['hmo.'.$action] = "SELECT 1 FROM {$table} r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id";
            }
            $bindings['hmo.invoice'] = "SELECT 1 FROM billing_invoices r JOIN hmo_invoice_links l ON l.invoice_id=r.id WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id";
            $bindings['hmo.decision-record'] = "SELECT 1 FROM hmo_decisions d JOIN hmo_claims c ON c.id=d.claim_id WHERE d.id=NEW.record_id AND c.account_id=NEW.account_id";
            foreach (['decision-review','allocation-review'] as $action) { $bindings['hmo.'.$action] = "SELECT 1 FROM hmo_reviews r WHERE r.id=NEW.record_id AND r.account_id=NEW.account_id"; }
        }
        $valid = [];
        foreach ($bindings as $action=>$query) { $valid[] = "(NEW.action='{$action}' AND EXISTS ({$query}))"; }
        $this->guard('billing_events','insert','billing_event_binding','NOT ('.implode(' OR ',$valid).')');
    }

    private function signature(Blueprint $t): void
    {
        $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->dateTime('recorded_at',6); $t->text('reason');
    }

    private function guard(string $table,string $event,string $name,string $condition): void
    {
        if (DB::getDriverName()==='mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition}
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='HMO integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT,'HMO integrity violation'); END");
        }
    }
};
