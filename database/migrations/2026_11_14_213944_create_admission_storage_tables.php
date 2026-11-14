<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wards', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->string('type', 64)->nullable();
            $table->decimal('daily_rate', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
        });
        Schema::create('beds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ward_id')->constrained()->restrictOnDelete();
            $table->string('label', 64);
            $table->decimal('daily_rate', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps(6);
            $table->unique(['ward_id', 'label']);
        });
        Schema::create('admissions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('encounter_id')->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreign(['encounter_id', 'patient_id'])->references(['id', 'patient_id'])->on('encounters')
                ->restrictOnDelete()->restrictOnUpdate();
            $status = $table->string('status', 24)->default('admitted');
            if (DB::getDriverName() === 'mysql') {
                $status->collation('utf8mb4_0900_as_cs');
            }
            $table->dateTime('admitted_at', 6);
            $table->foreignId('admitted_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('authorized_at', 6)->nullable();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('discharged_at', 6)->nullable();
            $table->foreignId('discharged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('discharge_reason')->nullable();
            $table->text('discharge_summary')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('active_patient_id')->nullable()
                ->virtualAs("CASE WHEN status IN ('admitted', 'discharge_authorized') THEN patient_id ELSE NULL END")->unique();
            $table->timestamps(6);
            $table->index(['status', 'admitted_at']);
            $table->index(['patient_id', 'admitted_at', 'id']);
        });
        Schema::create('bed_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bed_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->dateTime('assigned_at', 6);
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->text('assignment_reason');
            $table->dateTime('released_at', 6)->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('release_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedBigInteger('current_bed_id')->nullable()->virtualAs('CASE WHEN released_at IS NULL THEN bed_id ELSE NULL END')->unique();
            $table->unsignedBigInteger('current_admission_id')->nullable()->virtualAs('CASE WHEN released_at IS NULL THEN admission_id ELSE NULL END')->unique();
            $table->timestamps(6);
            $table->index(['bed_id', 'assigned_at', 'id']);
            $table->index(['admission_id', 'assigned_at', 'id']);
            $table->index(['released_at', 'bed_id', 'admission_id']);
        });

        DB::unprepared('CREATE VIEW current_bed_occupancies AS SELECT DISTINCT bed_id, admission_id, id AS allocation_id,
            created_at, updated_at FROM bed_allocations WHERE released_at IS NULL');
        $this->locks();
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        foreach (['wards' => ['code' => 64, 'name' => 191], 'beds' => ['label' => 64]] as $table => $fields) {
            $rules = ['NEW.version BETWEEN 1 AND 4294967294', 'NEW.is_active IN (0, 1)',
                '(NEW.daily_rate IS NULL OR (NEW.daily_rate >= 0 AND NEW.daily_rate <= 999999999999.99 AND round(NEW.daily_rate, 2) = NEW.daily_rate))'];
            foreach ($fields as $field => $max) {
                $rules[] = "{$length}(trim(NEW.{$field})) BETWEEN 1 AND {$max}";
            }
            if ($table === 'wards') {
                $rules[] = "(NEW.type IS NULL OR {$length}(trim(NEW.type)) BETWEEN 1 AND 64)";
            }
            $this->guard($table, implode(' AND ', $rules));
            $this->trigger($table, 'insert', $table.'_start_version', 'NEW.version <> 1', 'Catalog records must start at version one.');
            $identity = $table === 'wards' ? $this->changed('code') : 'NEW.ward_id <> OLD.ward_id';
            $this->trigger($table, 'update', $table.'_preserve_identity', 'NEW.id <> OLD.id OR '.$identity.' OR '.$this->changed('created_at')
                .' OR NEW.version <> OLD.version + 1', 'Catalog identity or version is invalid.');
        }
        $this->trigger('beds', 'update', 'beds_preserve_used_label', $this->changed('label').'
            AND EXISTS (SELECT 1 FROM bed_allocations a WHERE a.bed_id = OLD.id'.$this->locking().')', 'Used bed labels cannot be rewritten.');
        $this->trigger('beds', 'update', 'beds_retire_empty', 'NEW.is_active = 0 AND EXISTS
            (SELECT 1 FROM bed_allocations a WHERE a.bed_id = OLD.id AND a.released_at IS NULL'.$this->locking().')', 'Occupied beds cannot be retired.');
        $this->trigger('wards', 'update', 'wards_retire_empty', 'NEW.is_active = 0 AND EXISTS
            (SELECT 1 FROM beds b JOIN bed_allocations a ON a.bed_id = b.id WHERE b.ward_id = OLD.id AND a.released_at IS NULL'.$this->locking().')', 'Occupied wards cannot be retired.');

        $this->guard('admissions', "NEW.version BETWEEN 1 AND 4294967294 AND NEW.status IN ('admitted', 'discharge_authorized', 'discharged')
            AND EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.encounter_id AND e.patient_id = NEW.patient_id
                AND e.status IN ('open', 'in_progress') AND NEW.admitted_at >= e.opened_at".$this->locking().")
            AND (NEW.discharge_summary IS NULL OR {$length}(NEW.discharge_summary) <= 20000)
            AND ((NEW.status = 'admitted' AND NEW.authorized_at IS NULL AND NEW.authorized_by IS NULL
                AND NEW.discharged_at IS NULL AND NEW.discharged_by IS NULL AND NEW.discharge_reason IS NULL AND NEW.discharge_summary IS NULL)
            OR (NEW.status IN ('discharge_authorized', 'discharged') AND NEW.authorized_at IS NOT NULL AND NEW.authorized_by IS NOT NULL
                AND NEW.authorized_at >= NEW.admitted_at AND {$length}(trim(NEW.discharge_reason)) BETWEEN 5 AND 2000
                AND ((NEW.status = 'discharge_authorized' AND NEW.discharged_at IS NULL AND NEW.discharged_by IS NULL)
                    OR (NEW.status = 'discharged' AND NEW.discharged_at IS NOT NULL AND NEW.discharged_by IS NOT NULL
                        AND NEW.discharged_at >= NEW.authorized_at))))");
        $this->trigger('admissions', 'insert', 'admissions_start_admitted', "NEW.status <> 'admitted' OR NEW.version <> 1
            OR NOT EXISTS (SELECT 1 FROM patients p WHERE p.id = NEW.patient_id AND p.access_status = 'active'".$this->locking().")
            OR EXISTS (SELECT 1 FROM admissions a WHERE a.patient_id = NEW.patient_id
                AND (a.status <> 'discharged' OR a.discharged_at > NEW.admitted_at)".(DB::getDriverName() === 'mysql' ? ' FOR SHARE' : '').')',
            'Admissions must start after earlier inpatient episodes ended against an active identity.');
        $this->trigger('admissions', 'update', 'admissions_preserve_lifecycle', 'NEW.id <> OLD.id OR NEW.patient_id <> OLD.patient_id
            OR NEW.encounter_id <> OLD.encounter_id OR NEW.admitted_by <> OLD.admitted_by OR '.$this->changed('admitted_at').'
            OR '.$this->changed('created_at')." OR NEW.version <> OLD.version + 1
            OR NOT ((OLD.status = 'admitted' AND NEW.status = 'discharge_authorized') OR (OLD.status = 'discharge_authorized' AND NEW.status = 'discharged'))
            OR (OLD.status = 'discharge_authorized' AND (".$this->changed('authorized_at').' OR '.$this->changed('authorized_by')
                .' OR '.$this->changed('discharge_reason').' OR '.$this->changed('discharge_summary').'))', 'Admission identity, authorization, version or transition is invalid.');
        $this->trigger('admissions', 'update', 'admissions_authorization_time', "OLD.status = 'admitted' AND EXISTS
            (SELECT 1 FROM bed_allocations a WHERE a.admission_id = OLD.id
                AND (a.assigned_at > NEW.authorized_at OR a.released_at > NEW.authorized_at)".$this->locking().')', 'Authorization cannot precede recorded allocation activity.');
        $this->trigger('admissions', 'update', 'admissions_discharge_empty', "NEW.status = 'discharged' AND EXISTS
            (SELECT 1 FROM bed_allocations a WHERE a.admission_id = OLD.id AND (a.released_at IS NULL OR a.released_at > NEW.discharged_at)".$this->locking().')', 'Discharge requires all allocations to be released by the discharge time.');

        $this->guard('bed_allocations', "NEW.version BETWEEN 1 AND 4294967294 AND {$length}(trim(NEW.assignment_reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM admissions a JOIN encounters e ON e.id = a.encounter_id
                WHERE a.id = NEW.admission_id AND a.status IN ('admitted', 'discharge_authorized')
                AND e.status IN ('open', 'in_progress') AND NEW.assigned_at >= a.admitted_at".$this->locking().")
            AND ((NEW.released_at IS NULL AND NEW.released_by IS NULL AND NEW.release_reason IS NULL)
                OR (NEW.released_at IS NOT NULL AND NEW.released_at >= NEW.assigned_at AND NEW.released_by IS NOT NULL
                    AND {$length}(trim(NEW.release_reason)) BETWEEN 5 AND 2000))");
        $this->trigger('bed_allocations', 'insert', 'allocations_start_current', 'NEW.version <> 1 OR NEW.released_at IS NOT NULL
            OR NOT EXISTS (SELECT 1 FROM beds b JOIN wards w ON w.id = b.ward_id WHERE b.id = NEW.bed_id AND b.is_active = 1 AND w.is_active = 1'.$this->locking().')
            OR EXISTS (SELECT 1 FROM bed_allocations a WHERE (a.bed_id = NEW.bed_id OR a.admission_id = NEW.admission_id)
                AND (a.released_at IS NULL OR a.released_at > NEW.assigned_at)'.(DB::getDriverName() === 'mysql' ? ' FOR SHARE' : '').')', 'Allocate an active available bed after earlier allocations ended.');
        $this->trigger('bed_allocations', 'update', 'allocations_preserve_history', 'NEW.id <> OLD.id OR NEW.bed_id <> OLD.bed_id
            OR NEW.admission_id <> OLD.admission_id OR NEW.assigned_by <> OLD.assigned_by OR '.$this->changed('assigned_at').'
            OR '.$this->changed('assignment_reason').' OR '.$this->changed('created_at').'
            OR OLD.released_at IS NOT NULL OR NEW.released_at IS NULL OR NEW.version <> OLD.version + 1', 'Allocation history can only be released once.');

        $this->trigger('encounters', 'update', 'encounters_preserve_admissions', "NEW.status IN ('closed', 'cancelled') AND EXISTS
            (SELECT 1 FROM admissions a WHERE a.encounter_id = OLD.id
                AND (a.status <> 'discharged' OR a.discharged_at > NEW.closed_at)".$this->locking().')', 'An encounter cannot end before its admission is discharged.');
        foreach (['wards', 'beds', 'admissions', 'bed_allocations'] as $table) {
            $this->trigger($table, 'delete', $table.'_retain_history', '1 = 1', 'Admission and location records cannot be deleted.');
        }
        if (DB::getDriverName() === 'sqlite') {
            foreach (['admissions' => ['admitted_at', 'authorized_at', 'discharged_at'], 'bed_allocations' => ['assigned_at', 'released_at']] as $table => $columns) {
                foreach ($columns as $column) {
                    $this->guardDate($table, $column);
                }
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS encounters_preserve_admissions');
        DB::unprepared('DROP VIEW IF EXISTS current_bed_occupancies');
        foreach (['bed_allocations', 'admissions', 'beds', 'wards'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function locks(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        foreach (['insert', 'update'] as $operation) {
            $this->lockTrigger('admissions', $operation, 'DECLARE locked_id BIGINT UNSIGNED;
                SELECT id INTO locked_id FROM patients WHERE id = NEW.patient_id FOR UPDATE;
                SELECT id INTO locked_id FROM encounters WHERE id = NEW.encounter_id FOR UPDATE;');
            $this->lockTrigger('bed_allocations', $operation, 'DECLARE locked_id BIGINT UNSIGNED; DECLARE patient_key BIGINT UNSIGNED;
                DECLARE encounter_key BIGINT UNSIGNED; DECLARE ward_key BIGINT UNSIGNED;
                SELECT patient_id, encounter_id INTO patient_key, encounter_key FROM admissions WHERE id = NEW.admission_id;
                SELECT ward_id INTO ward_key FROM beds WHERE id = NEW.bed_id;
                SELECT id INTO locked_id FROM patients WHERE id = patient_key FOR UPDATE;
                SELECT id INTO locked_id FROM encounters WHERE id = encounter_key FOR UPDATE;
                SELECT id INTO locked_id FROM wards WHERE id = ward_key FOR UPDATE;
                SELECT id INTO locked_id FROM beds WHERE id = NEW.bed_id FOR UPDATE;
                SELECT id INTO locked_id FROM admissions WHERE id = NEW.admission_id FOR UPDATE;');
            $this->lockTrigger('beds', $operation, 'DECLARE locked_id BIGINT UNSIGNED;
                SELECT id INTO locked_id FROM wards WHERE id = NEW.ward_id FOR UPDATE;');
        }
    }

    private function lockTrigger(string $table, string $operation, string $body): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_lock_{$operation} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END");
    }

    private function changed(string $column): string
    {
        return DB::getDriverName() === 'mysql' ? "NOT (CAST(NEW.{$column} AS BINARY) <=> CAST(OLD.{$column} AS BINARY))" : "NEW.{$column} IS NOT OLD.{$column}";
    }

    private function locking(): string
    {
        return DB::getDriverName() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function guard(string $table, string $condition): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_validate_'.$operation, "({$condition}) IS NOT TRUE", 'Invalid admission storage record.');
        }
    }

    private function guardDate(string $table, string $column): void
    {
        foreach (['insert', 'update'] as $operation) {
            $this->trigger($table, $operation, $table.'_'.$column.'_'.$operation, "NEW.{$column} IS NOT NULL
                AND (length(NEW.{$column}) <> 26 OR substr(NEW.{$column}, 20, 1) <> '.'
                    OR substr(NEW.{$column}, 21) GLOB '*[^0-9]*' OR julianday(substr(NEW.{$column}, 1, 19)) IS NULL
                    OR strftime('%Y-%m-%d %H:%M:%S', substr(NEW.{$column}, 1, 19)) <> substr(NEW.{$column}, 1, 19))", 'Use a UTC timestamp with six fractional digits.');
        }
    }

    private function trigger(string $table, string $operation, string $name, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }
};
