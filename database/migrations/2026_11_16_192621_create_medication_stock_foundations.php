<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_units', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $this->provenance($table);
        });
        Schema::create('drugs', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->string('generic_name', 191)->nullable();
            $table->string('brand_name', 191)->nullable();
            $table->string('strength', 191)->nullable();
            $table->string('dosage_form', 191)->nullable();
            $table->string('category', 191)->nullable();
            $table->foreignId('base_unit_id')->constrained('stock_units')->restrictOnDelete();
            $table->unsignedTinyInteger('quantity_scale')->default(6);
            $this->master($table);
            $table->index(['is_active', 'name']);
        });
        Schema::create('drug_packs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->unsignedInteger('version');
            $table->string('name', 191);
            $table->bigInteger('base_quantity_micros');
            $table->boolean('is_active')->default(true);
            $this->provenance($table);
            $table->unique(['drug_id', 'code', 'version']);
            $table->unique(['id', 'drug_id']);
        });
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->string('contact_name', 191)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('alternate_phone', 32)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('address', 2000)->nullable();
            $this->master($table);
        });
        Schema::create('stock_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $this->master($table);
        });
        Schema::create('drug_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->string('batch_number', 128);
            $table->string('manufacturer', 191);
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on');
            $this->provenance($table);
            $table->unique(['drug_id', 'manufacturer', 'batch_number']);
            $table->unique(['id', 'drug_id']);
            $table->index(['expires_on', 'drug_id']);
        });
        Schema::create('drug_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->char('currency', 3);
            $table->unsignedInteger('version');
            $table->bigInteger('amount_micros');
            $table->dateTime('effective_at', 6);
            $this->provenance($table);
            $table->unique(['drug_id', 'kind', 'currency', 'version']);
            $table->index(['drug_id', 'kind', 'currency', 'effective_at']);
        });
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->string('movement_key', 64)->unique();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->foreignId('batch_id');
            $table->foreignId('store_id')->constrained('stock_stores')->restrictOnDelete();
            $table->foreignId('pack_id')->nullable();
            $table->string('kind', 32);
            $table->bigInteger('quantity_micros');
            $table->bigInteger('unit_cost_micros')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('reference_type', 64);
            $table->string('reference_id', 128);
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_of')->nullable()->unique();
            $this->provenance($table);
            $table->foreign(['batch_id', 'drug_id'])->references(['id', 'drug_id'])->on('drug_batches')->restrictOnDelete();
            $table->foreign(['pack_id', 'drug_id'])->references(['id', 'drug_id'])->on('drug_packs')->restrictOnDelete();
            $table->unique(['id', 'store_id', 'batch_id']);
            $table->foreign(['reversal_of', 'store_id', 'batch_id'])->references(['id', 'store_id', 'batch_id'])->on('stock_movements')->restrictOnDelete();
            $table->index(['store_id', 'batch_id', 'id']);
            $table->index(['reference_type', 'reference_id']);
        });
        Schema::create('stock_series_heads', function (Blueprint $table): void {
            if (DB::getDriverName() === 'mysql') {
                $table->collation = 'utf8mb4_0900_as_cs';
            }
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(0);
            $table->unsignedBigInteger('last_id')->nullable();
            $table->dateTime('recorded_at', 6)->nullable();
            $table->dateTime('effective_at', 6)->nullable();
            $table->primary(['drug_id', 'kind', 'code']);
        });
        Schema::create('stock_position_heads', function (Blueprint $table): void {
            $table->foreignId('store_id')->constrained('stock_stores')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('drug_batches')->restrictOnDelete();
            $table->bigInteger('quantity_micros')->default(0);
            $table->unsignedBigInteger('movement_count')->default(0);
            $table->unsignedBigInteger('last_id')->nullable();
            $table->dateTime('recorded_at', 6)->nullable();
            $table->primary(['store_id', 'batch_id']);
        });
        $this->headGuards();
        $this->catalogGuards();
        $this->ledgerGuards();
        DB::statement('CREATE VIEW stock_balances AS SELECT store_id, batch_id, drug_id, SUM(quantity_micros) AS quantity_micros,
            COUNT(*) AS movement_count, MAX(id) AS last_movement_id, MAX(recorded_at) AS last_changed_at
            FROM stock_movements GROUP BY store_id, batch_id, drug_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS stock_balances');
        foreach (['stock_position_heads', 'stock_series_heads', 'stock_movements', 'drug_prices', 'drug_batches', 'stock_stores', 'suppliers', 'drug_packs', 'drugs', 'stock_units'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function provenance(Blueprint $table): void
    {
        if (DB::getDriverName() === 'mysql') {
            $table->collation = 'utf8mb4_0900_as_cs';
        }
        $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
        $table->dateTime('recorded_at', 6);
        $table->string('reason', 2000);
    }

    private function master(Blueprint $table): void
    {
        $table->boolean('is_active')->default(true);
        $table->unsignedInteger('version')->default(1);
        $this->provenance($table);
        $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
        $table->dateTime('updated_at', 6);
        $table->string('change_reason', 2000);
    }

    private function catalogGuards(): void
    {
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        foreach (['stock_units', 'drugs', 'drug_packs', 'suppliers', 'stock_stores', 'drug_batches', 'drug_prices', 'stock_movements'] as $table) {
            $this->guard($table, 'insert', $table.'_provenance', "({$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND NEW.recorded_at IS NOT NULL) IS NOT TRUE");
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
            if (in_array($table, ['stock_units', 'drug_packs', 'drug_batches', 'drug_prices', 'stock_movements'], true)) {
                $this->guard($table, 'update', $table.'_immutable', '1 = 1');
            }
            if (DB::getDriverName() === 'sqlite') {
                $this->guard($table, 'insert', $table.'_date', 'julianday(NEW.recorded_at) IS NULL');
            }
        }
        foreach (['stock_units', 'drugs', 'drug_packs', 'suppliers', 'stock_stores'] as $table) {
            $this->guard($table, 'insert', $table.'_labels', "({$length}(trim(NEW.code)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191) IS NOT TRUE");
        }
        foreach (['drugs', 'suppliers', 'stock_stores'] as $table) {
            $valid = "NEW.is_active IN (0,1) AND NEW.version BETWEEN 1 AND 4294967294
                AND {$length}(trim(NEW.change_reason)) BETWEEN 5 AND 2000 AND NEW.updated_at >= NEW.recorded_at
                AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191";
            foreach (['insert', 'update'] as $event) {
                $this->guard($table, $event, $table.'_valid_'.$event, "({$valid}) IS NOT TRUE");
            }
            $this->guard($table, 'insert', $table.'_initial', 'NEW.version <> 1 OR NEW.updated_by <> NEW.created_by OR NEW.updated_at <> NEW.recorded_at');
            $identity = ['id', 'code', 'created_by', 'recorded_at', 'reason'];
            if ($table === 'drugs') {
                $identity = [...$identity, 'name', 'generic_name', 'brand_name', 'strength', 'dosage_form', 'category', 'base_unit_id', 'quantity_scale'];
            } elseif ($table === 'stock_stores') {
                $identity = [...$identity, 'department_id', 'kind'];
            }
            $changed = implode(' OR ', array_map(fn (string $column): string => $this->changed($column), $identity));
            $this->guard($table, 'update', $table.'_version', "{$changed} OR NEW.version <> OLD.version + 1 OR NEW.updated_at < OLD.updated_at");
            if (DB::getDriverName() === 'sqlite') {
                foreach (['insert', 'update'] as $event) {
                    $this->guard($table, $event, $table.'_updated_date_'.$event, 'julianday(NEW.updated_at) IS NULL');
                }
            }
        }
        $this->guard('drugs', 'insert', 'drugs_scale', '(NEW.quantity_scale BETWEEN 0 AND 6) IS NOT TRUE');
        $this->guard('drugs', 'insert', 'drugs_unit_clock', 'NOT EXISTS (SELECT 1 FROM stock_units u WHERE u.id = NEW.base_unit_id AND NEW.recorded_at >= u.recorded_at)');
        $this->guard('stock_stores', 'insert', 'stock_store_kind', "(NEW.kind IN ('main','dispensary','department')) IS NOT TRUE");
        $this->series('drug_packs', 'pack', 'NEW.code', "NEW.is_active IN (0,1) AND NEW.version > 0
            AND NEW.base_quantity_micros BETWEEN 1 AND 999999999999999
            AND EXISTS (SELECT 1 FROM drugs d WHERE d.id = NEW.drug_id AND NEW.recorded_at >= d.recorded_at
                AND NEW.base_quantity_micros % {$this->step()} = 0)");
        $currency = $this->currency('NEW.currency');
        $priceCode = DB::getDriverName() === 'mysql' ? "CONCAT(NEW.kind, ':', NEW.currency)" : "NEW.kind || ':' || NEW.currency";
        $this->series('drug_prices', 'price', $priceCode, "NEW.kind IN ('cost','selling') AND {$currency}
            AND NEW.amount_micros BETWEEN 0 AND 999999999999999 AND NEW.version > 0
            AND EXISTS (SELECT 1 FROM drugs d WHERE d.id = NEW.drug_id AND NEW.recorded_at >= d.recorded_at)
            AND NEW.effective_at >= NEW.recorded_at");
        $this->guard('drug_batches', 'insert', 'drug_batch_identity', "({$length}(trim(NEW.batch_number)) BETWEEN 1 AND 128
            AND {$length}(trim(NEW.manufacturer)) BETWEEN 1 AND 191 AND NEW.expires_on IS NOT NULL
            AND (NEW.manufactured_on IS NULL OR NEW.manufactured_on <= NEW.expires_on)
            AND EXISTS (SELECT 1 FROM drugs d WHERE d.id = NEW.drug_id AND NEW.recorded_at >= d.recorded_at)) IS NOT TRUE");
        if (DB::getDriverName() === 'sqlite') {
            $this->guard('drugs', 'insert', 'drugs_scale_integer', "typeof(NEW.quantity_scale) <> 'integer'");
            $this->guard('drug_batches', 'insert', 'drug_batch_dates', "date(NEW.expires_on, '+0 days') IS NOT NEW.expires_on
                OR (NEW.manufactured_on IS NOT NULL AND date(NEW.manufactured_on, '+0 days') IS NOT NEW.manufactured_on)");
            $this->guard('drug_prices', 'insert', 'drug_price_date', 'julianday(NEW.effective_at) IS NULL');
            foreach (['drug_packs' => 'base_quantity_micros', 'drug_prices' => 'amount_micros'] as $table => $column) {
                $this->guard($table, 'insert', $table.'_integer', "typeof(NEW.{$column}) <> 'integer'");
            }
        }
    }

    private function ledgerGuards(): void
    {
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $currency = $this->currency('NEW.currency');
        $this->guard('stock_movements', 'insert', 'stock_movement_valid', "(NEW.quantity_micros BETWEEN -999999999999999 AND 999999999999999
            AND NEW.quantity_micros <> 0 AND {$length}(trim(NEW.movement_key)) BETWEEN 1 AND 64
            AND {$length}(trim(NEW.reference_type)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.reference_id)) BETWEEN 1 AND 128
            AND ((NEW.unit_cost_micros IS NULL AND NEW.currency IS NULL)
                OR (NEW.unit_cost_micros BETWEEN 0 AND 999999999999999 AND {$currency}))
            AND ((NEW.kind IN ('opening','receipt','return') AND NEW.quantity_micros > 0 AND NEW.reversal_of IS NULL)
                OR (NEW.kind IN ('dispense','damage','expiry') AND NEW.quantity_micros < 0 AND NEW.reversal_of IS NULL)
                OR (NEW.kind IN ('transfer','adjustment') AND NEW.reversal_of IS NULL)
                OR (NEW.kind = 'reversal' AND NEW.reversal_of IS NOT NULL))
            AND EXISTS (SELECT 1 FROM drugs d WHERE d.id = NEW.drug_id AND NEW.quantity_micros % {$this->step()} = 0)
            AND EXISTS (SELECT 1 FROM drug_batches b WHERE b.id = NEW.batch_id AND NEW.recorded_at >= b.recorded_at)
            AND EXISTS (SELECT 1 FROM stock_stores s WHERE s.id = NEW.store_id AND NEW.recorded_at >= s.recorded_at)
            AND (NEW.pack_id IS NULL OR EXISTS (SELECT 1 FROM drug_packs p WHERE p.id = NEW.pack_id AND NEW.recorded_at >= p.recorded_at))) IS NOT TRUE");
        $costSame = $this->equal('NEW.unit_cost_micros', 'm.unit_cost_micros');
        $currencySame = $this->equal('NEW.currency', 'm.currency');
        $packSame = $this->equal('NEW.pack_id', 'm.pack_id');
        $supplierSame = $this->equal('NEW.supplier_id', 'm.supplier_id');
        $this->guard('stock_movements', 'insert', 'stock_reversal_valid', "NEW.reversal_of IS NOT NULL AND NOT EXISTS
            (SELECT 1 FROM stock_movements m WHERE m.id = NEW.reversal_of AND m.kind <> 'reversal'
                AND m.store_id = NEW.store_id AND m.batch_id = NEW.batch_id AND m.drug_id = NEW.drug_id
                AND m.quantity_micros = -NEW.quantity_micros AND NEW.recorded_at >= m.recorded_at
                AND {$costSame} AND {$currencySame} AND {$packSame} AND {$supplierSame})");
        $this->position();
        if (DB::getDriverName() === 'sqlite') {
            $this->guard('stock_movements', 'insert', 'stock_movement_integer', "typeof(NEW.quantity_micros) <> 'integer'
                OR (NEW.unit_cost_micros IS NOT NULL AND typeof(NEW.unit_cost_micros) <> 'integer')");
        }
    }

    private function series(string $table, string $kind, string $code, string $valid): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $effective = $kind === 'price' ? 'NEW.effective_at' : 'NULL';
        $key = "h.drug_id = NEW.drug_id AND h.kind = '{$kind}' AND h.code = ({$code})";
        $prepare = $mysql ? "DECLARE parent_lock BIGINT UNSIGNED; DECLARE current_version INT UNSIGNED DEFAULT NULL;
            SELECT id INTO parent_lock FROM drugs WHERE id = NEW.drug_id FOR UPDATE;
            SELECT version INTO current_version FROM stock_series_heads h WHERE {$key} FOR UPDATE;
            IF current_version IS NULL THEN
                INSERT INTO stock_series_heads (drug_id,kind,code) VALUES (NEW.drug_id,'{$kind}',{$code});
            END IF;" : "INSERT INTO stock_series_heads (drug_id,kind,code)
                SELECT NEW.drug_id,'{$kind}',{$code} WHERE NOT EXISTS (SELECT 1 FROM stock_series_heads h WHERE {$key});";
        $current = $mysql ? ' FOR UPDATE' : '';
        $valid .= " AND EXISTS (SELECT 1 FROM stock_series_heads h WHERE {$key} AND NEW.version = h.version + 1
            AND (h.recorded_at IS NULL OR NEW.recorded_at >= h.recorded_at)";
        if ($kind === 'price') {
            $valid .= ' AND (h.effective_at IS NULL OR NEW.effective_at >= h.effective_at)';
        }
        $valid .= "{$current})";
        $this->body($table, 'insert', $table.'_series', $prepare.$this->rejectSql("({$valid}) IS NOT TRUE"));
        $this->body($table, 'insert', $table.'_advance', "UPDATE stock_series_heads SET version = NEW.version, last_id = NEW.id,
            recorded_at = NEW.recorded_at, effective_at = {$effective} WHERE drug_id = NEW.drug_id AND kind = '{$kind}' AND code = ({$code});", 'AFTER');
    }

    private function position(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        $key = 'h.store_id = NEW.store_id AND h.batch_id = NEW.batch_id';
        $prepare = $mysql ? "DECLARE parent_lock BIGINT UNSIGNED; DECLARE position_id BIGINT UNSIGNED DEFAULT NULL;
            SELECT id INTO parent_lock FROM drug_batches WHERE id = NEW.batch_id FOR UPDATE;
            SELECT store_id INTO position_id FROM stock_position_heads h WHERE {$key} FOR UPDATE;
            IF position_id IS NULL THEN INSERT INTO stock_position_heads (store_id,batch_id) VALUES (NEW.store_id,NEW.batch_id); END IF;"
            : "INSERT INTO stock_position_heads (store_id,batch_id) SELECT NEW.store_id,NEW.batch_id
                WHERE NOT EXISTS (SELECT 1 FROM stock_position_heads h WHERE {$key});";
        $current = $mysql ? ' FOR UPDATE' : '';
        $valid = "EXISTS (SELECT 1 FROM stock_position_heads h WHERE {$key}
            AND h.quantity_micros + NEW.quantity_micros BETWEEN 0 AND 999999999999999
            AND (h.recorded_at IS NULL OR NEW.recorded_at >= h.recorded_at){$current})";
        $this->body('stock_movements', 'insert', 'stock_movement_position', $prepare.$this->rejectSql("({$valid}) IS NOT TRUE"));
        $this->body('stock_movements', 'insert', 'stock_movement_advance', 'UPDATE stock_position_heads SET
            quantity_micros = quantity_micros + NEW.quantity_micros, movement_count = movement_count + 1,
            last_id = NEW.id, recorded_at = NEW.recorded_at WHERE store_id = NEW.store_id AND batch_id = NEW.batch_id;', 'AFTER');
    }

    private function headGuards(): void
    {
        $this->guard('stock_series_heads', 'insert', 'stock_series_initial', "(NEW.kind IN ('pack','price') AND NEW.version = 0
            AND NEW.last_id IS NULL AND NEW.recorded_at IS NULL AND NEW.effective_at IS NULL) IS NOT TRUE");
        $this->guard('stock_position_heads', 'insert', 'stock_position_initial', '(NEW.quantity_micros = 0 AND NEW.movement_count = 0
            AND NEW.last_id IS NULL AND NEW.recorded_at IS NULL) IS NOT TRUE');
        foreach (['stock_series_heads', 'stock_position_heads'] as $table) {
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
        }
        $sameEffective = $this->equal('NEW.effective_at', 'p.effective_at');
        $sameNull = $this->equal('NEW.effective_at', 'NULL');
        $priceCode = DB::getDriverName() === 'mysql' ? "CONCAT(p.kind, ':', p.currency)" : "p.kind || ':' || p.currency";
        $this->guard('stock_series_heads', 'update', 'stock_series_advance_only', "(NEW.drug_id = OLD.drug_id AND NEW.kind = OLD.kind AND NEW.code = OLD.code
            AND NEW.version = OLD.version + 1 AND NEW.last_id > COALESCE(OLD.last_id,0)
            AND ((NEW.kind = 'pack' AND {$sameNull} AND EXISTS (SELECT 1 FROM drug_packs p WHERE p.id = NEW.last_id
                AND p.drug_id = NEW.drug_id AND p.code = NEW.code AND p.version = NEW.version AND p.recorded_at = NEW.recorded_at))
                OR (NEW.kind = 'price' AND EXISTS (SELECT 1 FROM drug_prices p WHERE p.id = NEW.last_id
                    AND p.drug_id = NEW.drug_id AND ({$priceCode}) = NEW.code AND p.version = NEW.version
                    AND p.recorded_at = NEW.recorded_at AND {$sameEffective})))) IS NOT TRUE");
        $this->guard('stock_position_heads', 'update', 'stock_position_advance_only', '(NEW.store_id = OLD.store_id AND NEW.batch_id = OLD.batch_id
            AND NEW.last_id > COALESCE(OLD.last_id,0) AND NEW.movement_count = OLD.movement_count + 1
            AND NEW.quantity_micros BETWEEN 0 AND 999999999999999
            AND EXISTS (SELECT 1 FROM stock_movements m WHERE m.id = NEW.last_id AND m.store_id = NEW.store_id AND m.batch_id = NEW.batch_id
                AND NEW.quantity_micros = OLD.quantity_micros + m.quantity_micros AND NEW.recorded_at = m.recorded_at)) IS NOT TRUE');
    }

    private function rejectSql(string $condition): string
    {
        return DB::getDriverName() === 'mysql'
            ? "IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medication stock integrity violation'; END IF;"
            : "SELECT CASE WHEN {$condition} THEN RAISE(ABORT, 'Medication stock integrity violation') END;";
    }

    private function body(string $table, string $event, string $name, string $sql, string $timing = 'BEFORE'): void
    {
        $row = DB::getDriverName() === 'mysql' ? ' FOR EACH ROW' : '';
        DB::unprepared("CREATE TRIGGER {$name} {$timing} {$event} ON {$table}{$row} BEGIN {$sql} END");
    }

    private function step(): string
    {
        return '(CASE d.quantity_scale WHEN 0 THEN 1000000 WHEN 1 THEN 100000 WHEN 2 THEN 10000 WHEN 3 THEN 1000 WHEN 4 THEN 100 WHEN 5 THEN 10 ELSE 1 END)';
    }

    private function currency(string $column): string
    {
        return DB::getDriverName() === 'mysql'
            ? "REGEXP_LIKE({$column}, '^[A-Z]{3}$', 'c')"
            : "(length({$column}) = 3 AND {$column} NOT GLOB '*[^A-Z]*')";
    }

    private function equal(string $left, string $right): string
    {
        return DB::getDriverName() === 'mysql' ? "(CAST({$left} AS BINARY) <=> CAST({$right} AS BINARY))" : "({$left} IS {$right})";
    }

    private function changed(string $column): string
    {
        return 'NOT '.$this->equal('NEW.'.$column, 'OLD.'.$column);
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medication stock integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Medication stock integrity violation'); END");
        }
    }
};
