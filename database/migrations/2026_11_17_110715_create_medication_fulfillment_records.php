<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['medication_authorizations', 'medication_dispenses', 'medication_returns',
        'medication_return_reviews', 'medication_administrations', 'medication_events'];

    public function up(): void
    {
        Schema::create('medication_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_item_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('drug_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('drug_version');
            $table->foreignId('assignment_id')->constrained('doctor_assignments')->restrictOnDelete();
            $table->unsignedInteger('assignment_version');
            $table->unsignedBigInteger('maximum_quantity_micros');
            $table->json('snapshot');
            $this->signature($table);
        });
        Schema::create('medication_dispenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->constrained('medication_authorizations')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('drug_batches')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stock_stores')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_micros');
            $table->foreignId('movement_id')->unique()->constrained('stock_movements')->restrictOnDelete();
            $this->signature($table);
        });
        Schema::create('medication_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispense_id')->constrained('medication_dispenses')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_micros');
            $table->string('disposition', 16);
            $table->boolean('usable_confirmed');
            $this->signature($table);
        });
        Schema::create('medication_return_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_id')->unique()->constrained('medication_returns')->restrictOnDelete();
            $table->string('decision', 16);
            $table->foreignId('movement_id')->nullable()->unique()->constrained('stock_movements')->restrictOnDelete();
            $this->signature($table);
        });
        Schema::create('medication_administrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dispense_id')->constrained('medication_dispenses')->restrictOnDelete();
            $table->foreignId('corrects_id')->nullable()->unique()->constrained('medication_administrations')->restrictOnDelete();
            $table->unsignedBigInteger('quantity_micros');
            $table->dateTime('observed_at', 6);
            $this->signature($table);
        });
        Schema::create('medication_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('authorization_id')->constrained('medication_authorizations')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 24);
            $table->unsignedBigInteger('record_id');
            $this->signature($table);
            $table->unique(['authorization_id', 'version']);
            $table->unique(['authorization_id', 'action', 'record_id'], 'medication_event_record_unique');
        });
        foreach (self::TABLES as $table) {
            $this->guard($table, 'update', $table.'_immutable_update', '1 = 1');
            $this->guard($table, 'delete', $table.'_immutable_delete', '1 = 1');
            $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
            $this->guard($table, 'insert', $table.'_signature', "{$length}(trim(NEW.reason)) < 5 OR {$length}(NEW.reason) > 2000");
            if (DB::getDriverName() === 'sqlite') {
                $this->guard($table, 'insert', $table.'_date', 'julianday(NEW.recorded_at) IS NULL');
            }
        }
        foreach (['medication_authorizations' => 'maximum_quantity_micros', 'medication_dispenses' => 'quantity_micros',
            'medication_returns' => 'quantity_micros', 'medication_administrations' => 'quantity_micros'] as $table => $column) {
            $minimum = $table === 'medication_administrations' ? 0 : 1;
            $this->guard($table, 'insert', $table.'_quantity', "NEW.{$column} NOT BETWEEN {$minimum} AND 999999999999999"
                .(DB::getDriverName() === 'sqlite' ? " OR typeof(NEW.{$column}) <> 'integer'" : ''));
        }
        $this->guard('medication_authorizations', 'insert', 'medication_authorization_identity', "NOT EXISTS (
            SELECT 1 FROM prescription_items i JOIN clinical_records r ON r.id = i.prescription_id
            JOIN prescriptions p ON p.clinical_record_id = r.id JOIN drugs d ON d.id = NEW.drug_id
            JOIN doctor_assignments c ON c.encounter_id = r.encounter_id
            WHERE i.id = NEW.prescription_item_id AND r.status = 'signed' AND c.id = NEW.assignment_id
            AND p.order_status = 'active' AND c.ended_at IS NULL AND d.version = NEW.drug_version AND d.is_active = 1
            AND c.doctor_id = NEW.actor_id AND c.version = NEW.assignment_version AND NEW.recorded_at >= r.finalized_at)");
        $this->guard('medication_dispenses', 'insert', 'medication_dispense_identity', "NOT EXISTS (
            SELECT 1 FROM medication_authorizations a JOIN drug_batches b ON b.drug_id = a.drug_id
            JOIN stock_movements m ON m.batch_id = b.id WHERE a.id = NEW.authorization_id AND b.id = NEW.batch_id
            AND m.id = NEW.movement_id AND m.drug_id = a.drug_id AND m.store_id = NEW.store_id
            AND m.kind = 'dispense' AND m.quantity_micros = -NEW.quantity_micros
            AND m.reversal_of IS NULL AND m.created_by = NEW.actor_id AND m.recorded_at = NEW.recorded_at)");
        $this->guard('medication_returns', 'insert', 'medication_return_disposition', "NEW.disposition NOT IN ('usable','damaged','expired')
            OR NEW.usable_confirmed NOT IN (0,1) OR (NEW.disposition = 'usable' AND NEW.usable_confirmed <> 1)
            OR (NEW.disposition <> 'usable' AND NEW.usable_confirmed <> 0)");
        $this->guard('medication_return_reviews', 'insert', 'medication_return_review_identity', "NEW.decision NOT IN ('approved','rejected')
            OR NOT EXISTS (SELECT 1 FROM medication_returns r JOIN medication_dispenses d ON d.id = r.dispense_id
                WHERE r.id = NEW.return_id AND r.actor_id <> NEW.actor_id AND NEW.recorded_at >= r.recorded_at
                AND ((NEW.decision = 'rejected' AND NEW.movement_id IS NULL)
                    OR (NEW.decision = 'approved' AND ((r.disposition <> 'usable' AND NEW.movement_id IS NULL)
                    OR (r.disposition = 'usable' AND EXISTS (SELECT 1 FROM stock_movements m JOIN medication_authorizations a ON a.id = d.authorization_id
                        WHERE m.id = NEW.movement_id AND m.batch_id = d.batch_id AND m.store_id = d.store_id
                        AND m.drug_id = a.drug_id AND m.kind = 'return' AND m.quantity_micros = r.quantity_micros
                        AND m.reversal_of IS NULL AND m.created_by = NEW.actor_id AND m.recorded_at = NEW.recorded_at))))))");
        $this->guard('medication_administrations', 'insert', 'medication_administration_identity', "NEW.observed_at > NEW.recorded_at
            OR NOT EXISTS (SELECT 1 FROM medication_dispenses d WHERE d.id = NEW.dispense_id AND NEW.observed_at >= d.recorded_at)
            OR (NEW.corrects_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM medication_administrations a
                WHERE a.id = NEW.corrects_id AND a.dispense_id = NEW.dispense_id AND a.actor_id = NEW.actor_id
                    AND NEW.recorded_at >= a.recorded_at)) OR (NEW.corrects_id IS NULL AND NEW.quantity_micros = 0)");
        if (DB::getDriverName() === 'sqlite') {
            $this->guard('medication_administrations', 'insert', 'medication_administration_observed_date', 'julianday(NEW.observed_at) IS NULL');
        }
        $this->guard('medication_events', 'insert', 'medication_event_sequence', "NEW.version NOT BETWEEN 1 AND 4294967293
            OR (NEW.version = 1 AND NEW.action <> 'authorize') OR (NEW.version > 1 AND (NEW.action = 'authorize'
                OR NOT EXISTS (SELECT 1 FROM medication_events e WHERE e.authorization_id = NEW.authorization_id
                    AND e.version = NEW.version - 1 AND e.recorded_at <= NEW.recorded_at)))");
        $bindings = [
            'authorize' => "SELECT a.id FROM medication_authorizations a WHERE a.id = NEW.authorization_id AND a.id = NEW.record_id",
            'dispense' => "SELECT d.id FROM medication_dispenses d WHERE d.authorization_id = NEW.authorization_id AND d.id = NEW.record_id",
            'return-request' => "SELECT r.id FROM medication_returns r JOIN medication_dispenses d ON d.id = r.dispense_id WHERE d.authorization_id = NEW.authorization_id AND r.id = NEW.record_id",
            'return-approve' => "SELECT v.id FROM medication_return_reviews v JOIN medication_returns r ON r.id = v.return_id JOIN medication_dispenses d ON d.id = r.dispense_id WHERE d.authorization_id = NEW.authorization_id AND v.id = NEW.record_id AND v.decision = 'approved'",
            'return-reject' => "SELECT v.id FROM medication_return_reviews v JOIN medication_returns r ON r.id = v.return_id JOIN medication_dispenses d ON d.id = r.dispense_id WHERE d.authorization_id = NEW.authorization_id AND v.id = NEW.record_id AND v.decision = 'rejected'",
            'administer' => "SELECT a.id FROM medication_administrations a JOIN medication_dispenses d ON d.id = a.dispense_id WHERE d.authorization_id = NEW.authorization_id AND a.id = NEW.record_id AND a.corrects_id IS NULL",
            'correct' => "SELECT a.id FROM medication_administrations a JOIN medication_dispenses d ON d.id = a.dispense_id WHERE d.authorization_id = NEW.authorization_id AND a.id = NEW.record_id AND a.corrects_id IS NOT NULL",
        ];
        $valid = [];
        foreach ($bindings as $action => $query) {
            $valid[] = "(NEW.action = '{$action}' AND EXISTS ({$query}))";
        }
        $this->guard('medication_events', 'insert', 'medication_event_binding', 'NOT ('.implode(' OR ', $valid).')');
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function signature(Blueprint $table): void
    {
        $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
        $table->dateTime('recorded_at', 6);
        $table->text('reason');
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Medication record integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Medication record integrity violation'); END");
        }
    }
};
