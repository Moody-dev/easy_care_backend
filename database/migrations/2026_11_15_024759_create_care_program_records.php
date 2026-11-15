<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('started_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('started_at', 6);
            $table->text('start_reason');
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('completed_encounter_id')->nullable()->constrained('encounters')->restrictOnDelete();
            $table->dateTime('completed_at', 6)->nullable();
            $table->text('completion_reason')->nullable();
            $table->timestamps(6);
            $table->index(['patient_id', 'type', 'status']);
        });
        Schema::create('births', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('care_programs')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('birth_order');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->text('reason');
            $table->unique(['delivery_id', 'birth_order']);
        });
        Schema::create('birth_patient_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('birth_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('child_patient_id')->unique()->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at', 6);
            $table->text('reason');
        });
        Schema::create('care_record_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clinical_record_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('care_program_id')->constrained()->restrictOnDelete();
            $table->foreignId('birth_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['care_program_id', 'birth_id']);
        });
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->guard('care_programs', 'insert', 'care_programs_start', "(NEW.type IN ('antenatal','postnatal','family_planning','delivery','immunization')
            AND NEW.status = 'active' AND NEW.version = 1 AND NEW.completed_at IS NULL AND NEW.completed_by IS NULL AND NEW.completed_encounter_id IS NULL
            AND NEW.completion_reason IS NULL AND {$length}(trim(NEW.start_reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM encounters e JOIN patients p ON p.id = e.patient_id WHERE e.id = NEW.encounter_id
                AND e.patient_id = NEW.patient_id AND e.status IN ('open','in_progress') AND p.access_status = 'active'
                AND NEW.started_at >= e.opened_at)) IS NOT TRUE");
        $this->guard('care_programs', 'update', 'care_programs_finish', "NEW.id <> OLD.id OR NEW.patient_id <> OLD.patient_id
            OR NEW.encounter_id <> OLD.encounter_id OR NEW.type <> OLD.type OR NEW.started_by <> OLD.started_by
            OR NEW.started_at <> OLD.started_at OR NEW.start_reason <> OLD.start_reason
            OR OLD.status <> 'active' OR NEW.status <> 'completed' OR NEW.version <> OLD.version + 1
            OR NEW.completed_by IS NULL OR NEW.completed_at IS NULL OR NEW.completed_at < OLD.started_at
            OR NOT EXISTS (SELECT 1 FROM encounters e WHERE e.id = NEW.completed_encounter_id AND e.patient_id = OLD.patient_id
                AND e.status IN ('open','in_progress') AND NEW.completed_at >= e.opened_at)
            OR ({$length}(trim(NEW.completion_reason)) BETWEEN 5 AND 2000) IS NOT TRUE");
        $this->guard('births', 'insert', 'births_delivery', "(NEW.birth_order BETWEEN 1 AND 4294967294
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND EXISTS
            (SELECT 1 FROM care_programs p JOIN encounters e ON e.id = NEW.encounter_id WHERE p.id = NEW.delivery_id
                AND p.type = 'delivery' AND p.status = 'active' AND e.patient_id = p.patient_id AND e.status IN ('open','in_progress')
                AND NEW.recorded_at >= p.started_at AND NEW.recorded_at >= e.opened_at)) IS NOT TRUE");
        $this->guard('birth_patient_links', 'insert', 'birth_link_identity', "({$length}(trim(NEW.reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM births b JOIN care_programs p ON p.id = b.delivery_id
                JOIN patients child ON child.id = NEW.child_patient_id
                JOIN encounters e ON e.id = NEW.encounter_id
                WHERE b.id = NEW.birth_id AND p.status = 'active' AND p.patient_id <> NEW.child_patient_id
                AND child.access_status = 'active' AND e.patient_id = p.patient_id AND e.status IN ('open','in_progress')
                AND NEW.verified_at >= b.recorded_at AND NEW.verified_at >= e.opened_at)) IS NOT TRUE");
        $this->guard('care_record_links', 'insert', 'care_link_identity', "(EXISTS
            (SELECT 1 FROM clinical_records r JOIN care_programs p ON p.id = NEW.care_program_id
                WHERE r.id = NEW.clinical_record_id AND r.patient_id = p.patient_id AND r.kind = 'entry'
                AND r.status = 'draft' AND p.status = 'active')
            AND (NEW.birth_id IS NULL OR EXISTS (SELECT 1 FROM births b WHERE b.id = NEW.birth_id AND b.delivery_id = NEW.care_program_id))
            AND NOT EXISTS (SELECT 1 FROM clinical_records r JOIN care_record_links original ON original.clinical_record_id = r.amends_id
                WHERE r.id = NEW.clinical_record_id AND (original.care_program_id <> NEW.care_program_id OR ".$this->different('original.birth_id', 'NEW.birth_id')."))) IS NOT TRUE");
        foreach (['care_programs','births','birth_patient_links','care_record_links'] as $table) {
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
            if ($table !== 'care_programs') {
                $this->guard($table, 'update', $table.'_immutable', '1 = 1');
            }
        }
        $this->guard('clinical_records', 'update', 'care_sign_template', "NEW.status = 'signed' AND EXISTS
            (SELECT 1 FROM care_record_links l WHERE l.clinical_record_id = NEW.id) AND NOT EXISTS
            (SELECT 1 FROM care_record_links l JOIN care_programs p ON p.id = l.care_program_id
                JOIN clinical_entries e ON e.clinical_record_id = NEW.id
                JOIN clinical_template_versions v ON v.id = e.clinical_template_version_id
                JOIN clinical_templates t ON t.id = v.clinical_template_id
                WHERE l.clinical_record_id = NEW.id AND p.status = 'active'
                    AND ".$this->templateMatch().")");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS care_sign_template');
        foreach (['care_record_links','birth_patient_links','births','care_programs'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function templateMatch(): string
    {
        if (DB::getDriverName() === 'mysql') {
            $prefix = "CONCAT('care.', CASE WHEN l.birth_id IS NULL THEN p.type ELSE 'birth' END, '.')";
            return "CAST(LEFT(t.code, CHAR_LENGTH({$prefix})) AS BINARY) = CAST({$prefix} AS BINARY)";
        }
        $prefix = "('care.' || CASE WHEN l.birth_id IS NULL THEN p.type ELSE 'birth' END || '.')";
        return "substr(t.code, 1, length({$prefix})) = {$prefix}";
    }

    private function different(string $left, string $right): string
    {
        return DB::getDriverName() === 'mysql' ? "NOT ({$left} <=> {$right})" : "{$left} IS NOT {$right}";
    }

    private function guard(string $table, string $operation, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid or immutable care-program record.'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Invalid or immutable care-program record.'); END");
        }
    }
};
