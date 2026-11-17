<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_store_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stock_stores')->restrictOnDelete();
            $table->boolean('is_active');
            $table->unsignedInteger('version');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('changed_at', 6);
            $table->string('reason', 2000);
            $table->unique(['user_id', 'store_id']);
        });
        Schema::create('stock_catalog_revisions', function (Blueprint $table): void {
            $table->id();
            $table->string('catalog', 32);
            $table->unsignedBigInteger('record_id');
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->string('reason', 2000);
            $table->json('snapshot');
            $table->unique(['catalog', 'record_id', 'version']);
        });
        Schema::create('stock_operations', function (Blueprint $table): void {
            $table->id();
            if (DB::getDriverName() === 'mysql') {
                $table->collation = 'utf8mb4_0900_as_cs';
            }
            $table->string('kind', 32);
            $table->string('status', 16)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('source_store_id')->nullable()->constrained('stock_stores')->restrictOnDelete();
            $table->foreignId('destination_store_id')->nullable()->constrained('stock_stores')->restrictOnDelete();
            $table->unsignedInteger('source_store_version')->nullable();
            $table->unsignedInteger('destination_store_version')->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('supplier_version')->nullable();
            $table->string('invoice_number', 128)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('reference', 128);
            $table->json('catalog_snapshot');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('prepared_at', 6);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->dateTime('posted_at', 6)->nullable();
            $table->foreignId('last_actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('last_changed_at', 6);
            $table->string('reason', 2000);
            $table->index(['status', 'kind', 'id']);
            $table->index(['source_store_id', 'id']);
            $table->index(['destination_store_id', 'id']);
        });
        Schema::create('stock_operation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('stock_operations')->restrictOnDelete();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('drug_version');
            $table->foreignId('batch_id');
            $table->foreignId('pack_id')->nullable();
            $table->bigInteger('pack_count_micros')->nullable();
            $table->bigInteger('quantity_micros');
            $table->bigInteger('unit_cost_micros')->nullable();
            $table->unsignedBigInteger('source_balance_version')->nullable();
            $table->json('catalog_snapshot');
            $table->foreign(['batch_id', 'drug_id'])->references(['id', 'drug_id'])->on('drug_batches')->restrictOnDelete();
            $table->foreign(['pack_id', 'drug_id'])->references(['id', 'drug_id'])->on('drug_packs')->restrictOnDelete();
            $table->unique(['operation_id', 'batch_id']);
        });
        Schema::create('stock_operation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained('stock_operations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->string('reason', 2000);
            $table->json('snapshot');
            $table->unique(['operation_id', 'version']);
        });
        Schema::create('stock_invoice_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->unique()->constrained('stock_operations')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->char('invoice_key', 64);
            $table->unique(['supplier_id', 'invoice_key']);
        });
        Schema::create('stock_operation_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('line_id')->constrained('stock_operation_lines')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stock_stores')->restrictOnDelete();
            $table->foreignId('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $table->unique(['line_id', 'store_id']);
        });
        $this->guards();
    }

    public function down(): void
    {
        foreach (['stock_document_movement', 'stock_document_leg'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        foreach (['stock_operation_legs', 'stock_invoice_receipts', 'stock_operation_revisions', 'stock_operation_lines', 'stock_operations',
            'stock_catalog_revisions', 'stock_store_accesses'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function guards(): void
    {
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->guard('stock_store_accesses', 'insert', 'stock_access_initial', '(NEW.version = 1 AND NEW.is_active IN (0,1)) IS NOT TRUE');
        $this->guard('stock_store_accesses', 'update', 'stock_access_version', '(NEW.id = OLD.id AND NEW.user_id = OLD.user_id AND NEW.store_id = OLD.store_id
            AND NEW.version = OLD.version + 1 AND NEW.is_active IN (0,1) AND NEW.changed_at >= OLD.changed_at) IS NOT TRUE');
        $this->guard('stock_store_accesses', 'delete', 'stock_access_retain', '1 = 1');
        foreach (['insert', 'update'] as $event) {
            $this->guard('stock_store_accesses', $event, 'stock_access_reason_'.$event, "({$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND NEW.changed_at IS NOT NULL) IS NOT TRUE");
            $valid = "NEW.version BETWEEN 1 AND 4294967294 AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000
                AND {$length}(trim(NEW.reference)) BETWEEN 1 AND 128 AND NEW.last_changed_at >= NEW.prepared_at
                AND ((NEW.kind IN ('receipt','opening','adjustment_in') AND NEW.source_store_id IS NULL AND NEW.source_store_version IS NULL
                    AND NEW.destination_store_id IS NOT NULL AND NEW.destination_store_version > 0)
                    OR (NEW.kind IN ('damage','expiry','adjustment_out') AND NEW.source_store_id IS NOT NULL AND NEW.source_store_version > 0
                        AND NEW.destination_store_id IS NULL AND NEW.destination_store_version IS NULL)
                    OR (NEW.kind IN ('transfer','requisition') AND NEW.source_store_id IS NOT NULL AND NEW.destination_store_id IS NOT NULL
                        AND NEW.source_store_id <> NEW.destination_store_id AND NEW.source_store_version > 0 AND NEW.destination_store_version > 0))
                AND ((NEW.kind = 'receipt' AND NEW.supplier_id IS NOT NULL AND NEW.supplier_version > 0
                        AND {$length}(trim(NEW.invoice_number)) BETWEEN 1 AND 128 AND NEW.currency IS NOT NULL)
                    OR (NEW.kind <> 'receipt' AND NEW.supplier_id IS NULL AND NEW.supplier_version IS NULL AND NEW.invoice_number IS NULL))
                AND (NEW.currency IS NULL OR ".$this->currency('NEW.currency').")
                AND ((NEW.status IN ('draft','submitted','cancelled') AND NEW.reviewed_by IS NULL AND NEW.reviewed_at IS NULL AND NEW.posted_at IS NULL)
                    OR (NEW.status IN ('posting','posted','rejected') AND NEW.reviewed_by IS NOT NULL AND NEW.reviewed_by <> NEW.prepared_by
                        AND NEW.reviewed_at >= NEW.prepared_at AND NEW.last_changed_at >= NEW.reviewed_at
                        AND ((NEW.status = 'posted' AND NEW.posted_at >= NEW.reviewed_at AND NEW.last_changed_at >= NEW.posted_at)
                            OR (NEW.status <> 'posted' AND NEW.posted_at IS NULL))))";
            $this->guard('stock_operations', $event, 'stock_operation_valid_'.$event, "({$valid}) IS NOT TRUE");
        }
        $this->guard('stock_operations', 'insert', 'stock_operation_initial', "(NEW.status = 'draft' AND NEW.version = 1
            AND NEW.last_actor_id = NEW.prepared_by AND NEW.last_changed_at = NEW.prepared_at) IS NOT TRUE");
        $fixed = implode(' OR ', array_map(fn (string $column): string => 'NOT '.$this->same('NEW.'.$column, 'OLD.'.$column), ['id', 'kind', 'prepared_by', 'prepared_at']));
        $content = implode(' OR ', array_map(fn (string $column): string => 'NOT '.$this->same('NEW.'.$column, 'OLD.'.$column),
            ['source_store_id', 'destination_store_id', 'source_store_version', 'destination_store_version', 'supplier_id', 'supplier_version', 'invoice_number', 'currency', 'reference', 'catalog_snapshot']));
        $this->guard('stock_operations', 'update', 'stock_operation_transition', "({$fixed}) OR NEW.version <> OLD.version + 1
            OR NEW.last_changed_at < OLD.last_changed_at OR (OLD.status <> 'draft' AND ({$content}))
            OR NOT ((OLD.status = 'draft' AND NEW.status IN ('draft','submitted','cancelled') AND NEW.last_actor_id = OLD.prepared_by)
                OR (OLD.status = 'submitted' AND NEW.status = 'cancelled' AND NEW.last_actor_id = OLD.prepared_by)
                OR (OLD.status = 'submitted' AND NEW.status IN ('posting','rejected') AND NEW.last_actor_id = NEW.reviewed_by)
                OR (OLD.status = 'posting' AND NEW.status = 'posted' AND NEW.reviewed_by = OLD.reviewed_by
                    AND NEW.reviewed_at = OLD.reviewed_at AND NEW.last_actor_id = OLD.reviewed_by))");
        $this->guard('stock_operations', 'delete', 'stock_operation_retain', '1 = 1');
        foreach (['insert', 'update', 'delete'] as $event) {
            $row = $event === 'delete' ? 'OLD' : 'NEW';
            $this->guard('stock_operation_lines', $event, 'stock_line_draft_'.$event, "NOT EXISTS (SELECT 1 FROM stock_operations o WHERE o.id = {$row}.operation_id AND o.status = 'draft')");
        }
        $this->guard('stock_operation_lines', 'update', 'stock_line_identity', 'NEW.id <> OLD.id OR NEW.operation_id <> OLD.operation_id');
        foreach (['insert', 'update'] as $event) {
            $this->guard('stock_operation_lines', $event, 'stock_line_values_'.$event, "(NEW.quantity_micros BETWEEN 1 AND 999999999999999
                AND NEW.drug_version > 0 AND (NEW.unit_cost_micros IS NULL OR NEW.unit_cost_micros BETWEEN 0 AND 999999999999999)
                AND ((NEW.pack_id IS NULL AND NEW.pack_count_micros IS NULL)
                    OR (NEW.pack_id IS NOT NULL AND NEW.pack_count_micros BETWEEN 1 AND 999999999999999))
                AND EXISTS (SELECT 1 FROM stock_operations o WHERE o.id = NEW.operation_id
                    AND (o.source_store_id IS NULL OR NEW.source_balance_version IS NOT NULL)
                    AND (NEW.unit_cost_micros IS NULL OR o.currency IS NOT NULL)
                    AND (o.kind NOT IN ('receipt','opening') OR (NEW.unit_cost_micros IS NOT NULL AND o.currency IS NOT NULL)))) IS NOT TRUE");
        }
        foreach (['stock_operation_revisions', 'stock_catalog_revisions', 'stock_invoice_receipts', 'stock_operation_legs'] as $table) {
            $this->guard($table, 'update', $table.'_immutable', '1 = 1');
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
        }
        $this->guard('stock_operation_revisions', 'insert', 'stock_revision_current', "(NEW.version > 0 AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM stock_operations o WHERE o.id = NEW.operation_id AND o.version = NEW.version
                AND o.last_actor_id = NEW.actor_id AND o.last_changed_at = NEW.recorded_at)) IS NOT TRUE");
        $this->guard('stock_catalog_revisions', 'insert', 'stock_catalog_revision_valid', "(NEW.catalog IN ('units','drugs','packs','suppliers','stores','batches','prices','store-access')
            AND NEW.record_id > 0 AND NEW.version > 0 AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000) IS NOT TRUE");
        $catalogReferences = [];
        foreach (['units' => 'stock_units', 'drugs' => 'drugs', 'packs' => 'drug_packs', 'suppliers' => 'suppliers',
            'stores' => 'stock_stores', 'batches' => 'drug_batches', 'prices' => 'drug_prices', 'store-access' => 'stock_store_accesses'] as $catalog => $table) {
            $version = in_array($catalog, ['units','batches'], true) ? '1' : 'r.version';
            $date = $catalog === 'store-access' ? 'changed_at' : (in_array($catalog, ['drugs','stores','suppliers'], true) ? 'updated_at' : 'recorded_at');
            $catalogReferences[] = "(NEW.catalog = '{$catalog}' AND EXISTS (SELECT 1 FROM {$table} r WHERE r.id = NEW.record_id
                AND NEW.version = {$version} AND NEW.recorded_at >= r.{$date}))";
        }
        $this->guard('stock_catalog_revisions', 'insert', 'stock_catalog_revision_reference', 'NOT ('.implode(' OR ', $catalogReferences).')');
        $this->guard('stock_invoice_receipts', 'insert', 'stock_invoice_identity', "(length(NEW.invoice_key) = 64 AND EXISTS (SELECT 1 FROM stock_operations o
            WHERE o.id = NEW.operation_id AND o.kind = 'receipt' AND o.status = 'posting' AND o.supplier_id = NEW.supplier_id)) IS NOT TRUE");
        $this->guard('stock_operations', 'update', 'stock_document_posted', "NEW.status = 'posted' AND (
            NOT EXISTS (SELECT 1 FROM stock_operation_lines l WHERE l.operation_id = NEW.id)
            OR EXISTS (SELECT 1 FROM stock_operation_lines l WHERE l.operation_id = NEW.id AND
                (SELECT COUNT(*) FROM stock_operation_legs m WHERE m.line_id = l.id)
                    <> CASE WHEN NEW.kind IN ('transfer','requisition') THEN 2 ELSE 1 END)
            OR (NEW.kind = 'receipt' AND NOT EXISTS (SELECT 1 FROM stock_invoice_receipts i WHERE i.operation_id = NEW.id)))");
        $currencySame = $this->same('NEW.currency', '(CASE WHEN l.unit_cost_micros IS NULL THEN NULL ELSE o.currency END)');
        $costSame = $this->same('NEW.unit_cost_micros', 'l.unit_cost_micros');
        $packSame = $this->same('NEW.pack_id', 'l.pack_id');
        $supplierSame = $this->same('NEW.supplier_id', 'o.supplier_id');
        $referenceSame = $this->same('NEW.reference_id', 'CAST(l.id AS CHAR)');
        $movementReferenceSame = $this->same('m.reference_id', 'CAST(l.id AS CHAR)');
        $this->guard('stock_movements', 'insert', 'stock_document_movement', "NEW.reference_type = 'stock_operation_line'
            AND NOT EXISTS (SELECT 1 FROM stock_operation_lines l JOIN stock_operations o ON o.id = l.operation_id
                WHERE {$referenceSame} AND o.status = 'posting' AND o.reviewed_by = NEW.created_by
                    AND NEW.recorded_at = o.last_changed_at AND NEW.reversal_of IS NULL
                    AND NEW.drug_id = l.drug_id AND NEW.batch_id = l.batch_id AND {$currencySame} AND {$costSame} AND {$packSame} AND {$supplierSame}
                    AND ((NEW.store_id = o.destination_store_id AND NEW.quantity_micros = l.quantity_micros)
                        OR (NEW.store_id = o.source_store_id AND NEW.quantity_micros = -l.quantity_micros))
                    AND NEW.kind = CASE WHEN o.kind IN ('transfer','requisition') THEN 'transfer'
                        WHEN o.kind IN ('adjustment_in','adjustment_out') THEN 'adjustment' ELSE o.kind END)");
        $this->guard('stock_operation_legs', 'insert', 'stock_leg_identity', "NOT EXISTS (SELECT 1 FROM stock_movements m
            JOIN stock_operation_lines l ON {$movementReferenceSame} JOIN stock_operations o ON o.id = l.operation_id
            WHERE m.id = NEW.movement_id AND m.reference_type = 'stock_operation_line' AND NEW.line_id = l.id
                AND m.store_id = NEW.store_id AND o.status = 'posting')");
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER stock_document_leg AFTER INSERT ON stock_movements FOR EACH ROW BEGIN
                IF NEW.reference_type = 'stock_operation_line' THEN INSERT INTO stock_operation_legs (line_id,store_id,movement_id)
                    VALUES (CAST(NEW.reference_id AS UNSIGNED),NEW.store_id,NEW.id); END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER stock_document_leg AFTER INSERT ON stock_movements WHEN NEW.reference_type = 'stock_operation_line'
                BEGIN INSERT INTO stock_operation_legs (line_id,store_id,movement_id) VALUES (CAST(NEW.reference_id AS INTEGER),NEW.store_id,NEW.id); END");
            foreach (['stock_store_accesses' => ['changed_at'], 'stock_operations' => ['prepared_at','reviewed_at','posted_at','last_changed_at'],
                'stock_operation_revisions' => ['recorded_at'], 'stock_catalog_revisions' => ['recorded_at']] as $table => $columns) {
                foreach ($columns as $column) {
                    foreach (['insert','update'] as $event) {
                        $this->guard($table, $event, $table.'_'.$column.'_'.$event, "NEW.{$column} IS NOT NULL AND julianday(NEW.{$column}) IS NULL");
                    }
                }
            }
            foreach (['quantity_micros','pack_count_micros','unit_cost_micros','source_balance_version','drug_version'] as $column) {
                foreach (['insert','update'] as $event) {
                    $this->guard('stock_operation_lines', $event, 'stock_line_'.$column.'_'.$event,
                        "NEW.{$column} IS NOT NULL AND typeof(NEW.{$column}) <> 'integer'");
                }
            }
        }
    }

    private function currency(string $column): string
    {
        return DB::getDriverName() === 'mysql' ? "REGEXP_LIKE({$column}, '^[A-Z]{3}$', 'c')"
            : "(length({$column}) = 3 AND {$column} NOT GLOB '*[^A-Z]*')";
    }

    private function same(string $left, string $right): string
    {
        return DB::getDriverName() === 'mysql' ? "(CAST({$left} AS BINARY) <=> CAST({$right} AS BINARY))" : "({$left} IS {$right})";
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock operation integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Stock operation integrity violation'); END");
        }
    }
};
