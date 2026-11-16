<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('ordered_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained('doctor_assignments')->restrictOnDelete();
            $table->unsignedInteger('assignment_version');
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('requested_at', 6);
            $table->dateTime('last_changed_at', 6);
            $table->mediumText('clinical_context')->nullable();
            $table->text('reason');
            $table->timestamps(6);
            $table->unique(['id', 'encounter_id']);
            $table->index(['patient_id', 'requested_at']);
        });
        Schema::create('investigation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('investigation_orders')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreign(['order_id', 'encounter_id'])->references(['id', 'encounter_id'])->on('investigation_orders')->restrictOnDelete();
            $table->foreignId('test_id')->constrained('investigation_tests')->restrictOnDelete();
            $table->foreignId('schema_id')->constrained('investigation_schemas')->restrictOnDelete();
            $table->foreign(['schema_id', 'test_id'])->references(['id', 'test_id'])->on('investigation_schemas')->restrictOnDelete();
            $table->string('department', 32);
            $table->string('priority', 16);
            $table->string('status', 16)->default('requested');
            $table->unsignedInteger('version')->default(1);
            $table->string('specimen_ref', 191)->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('collected_at', 6)->nullable();
            $table->foreignId('processing_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('processing_at', 6)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->dateTime('last_event_at', 6);
            $table->unique(['order_id', 'test_id']);
            $table->unique(['id', 'test_id', 'schema_id', 'encounter_id'], 'investigation_item_result_identity');
            $table->index(['department', 'status', 'priority', 'id']);
        });
        Schema::create('investigation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('investigation_orders')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('investigation_items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->text('reason');
            $table->json('snapshot');
            $table->unique(['order_id', 'version']);
        });
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->guard('investigation_orders', 'insert', 'investigation_order_initial', "(NEW.version = 1 AND NEW.last_changed_at = NEW.requested_at
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND EXISTS
            (SELECT 1 FROM encounters e JOIN patients p ON p.id = e.patient_id JOIN doctor_assignments a ON a.encounter_id = e.id
                WHERE e.id = NEW.encounter_id AND e.patient_id = NEW.patient_id AND p.access_status = 'active'
                AND e.status IN ('open','in_progress') AND a.id = NEW.assignment_id AND a.version = NEW.assignment_version
                AND a.doctor_id = NEW.ordered_by AND a.ended_at IS NULL AND a.status IN ('assigned','accepted')
                AND NEW.requested_at >= e.opened_at AND NEW.requested_at >= a.assigned_at)) IS NOT TRUE");
        $contextSame = $this->same('NEW.clinical_context', 'OLD.clinical_context');
        $this->guard('investigation_orders', 'update', 'investigation_order_history', "(NEW.id = OLD.id AND NEW.patient_id = OLD.patient_id
            AND NEW.encounter_id = OLD.encounter_id AND NEW.ordered_by = OLD.ordered_by AND NEW.assignment_id = OLD.assignment_id
            AND NEW.assignment_version = OLD.assignment_version AND NEW.requested_at = OLD.requested_at AND NEW.reason = OLD.reason
            AND {$contextSame} AND NEW.created_at = OLD.created_at AND NEW.version = OLD.version + 1
            AND NEW.last_changed_at >= OLD.last_changed_at
            AND EXISTS (SELECT 1 FROM encounters e JOIN patients p ON p.id = e.patient_id WHERE e.id = OLD.encounter_id
                AND e.status IN ('open','in_progress') AND p.access_status = 'active')) IS NOT TRUE");
        $this->guard('investigation_items', 'insert', 'investigation_item_initial', "(NEW.version = 1 AND NEW.status = 'requested'
            AND NEW.priority IN ('routine','urgent') AND NEW.collected_at IS NULL AND NEW.collected_by IS NULL AND NEW.specimen_ref IS NULL
            AND NEW.processing_at IS NULL AND NEW.processing_by IS NULL AND NEW.cancelled_at IS NULL AND NEW.cancelled_by IS NULL AND NEW.cancellation_reason IS NULL
            AND (SELECT COUNT(*) FROM investigation_items i WHERE i.order_id = NEW.order_id) < 20
            AND NOT EXISTS (SELECT 1 FROM investigation_revisions r WHERE r.order_id = NEW.order_id)
            AND EXISTS (SELECT 1 FROM investigation_orders o JOIN encounters e ON e.id = o.encounter_id WHERE o.id = NEW.order_id
                AND o.encounter_id = NEW.encounter_id AND o.version = 1 AND e.status IN ('open','in_progress') AND NEW.last_event_at = o.requested_at)
            AND EXISTS (SELECT 1 FROM investigation_tests t JOIN investigation_schemas s ON s.test_id = t.id WHERE t.id = NEW.test_id
                AND t.is_active = 1 AND t.department = NEW.department AND s.id = NEW.schema_id AND s.version = t.version AND s.published_at <= NEW.last_event_at)) IS NOT TRUE");
        $collectionSame = $this->same('NEW.collected_at', 'OLD.collected_at').' AND '.$this->same('NEW.collected_by', 'OLD.collected_by').' AND '.$this->same('NEW.specimen_ref', 'OLD.specimen_ref');
        $this->guard('investigation_items', 'update', 'investigation_item_transition', "(NEW.id = OLD.id AND NEW.order_id = OLD.order_id
            AND NEW.encounter_id = OLD.encounter_id AND NEW.test_id = OLD.test_id AND NEW.schema_id = OLD.schema_id AND NEW.department = OLD.department
            AND NEW.priority = OLD.priority AND NEW.version = OLD.version + 1 AND NEW.last_event_at >= OLD.last_event_at
            AND EXISTS (SELECT 1 FROM encounters e JOIN patients p ON p.id = e.patient_id WHERE e.id = OLD.encounter_id
                AND e.status IN ('open','in_progress') AND p.access_status = 'active')
            AND ((OLD.status = 'requested' AND OLD.department = 'laboratory' AND NEW.status = 'collected'
                    AND NEW.collected_by IS NOT NULL AND NEW.collected_at = NEW.last_event_at AND {$length}(trim(NEW.specimen_ref)) BETWEEN 1 AND 191
                    AND NEW.processing_by IS NULL AND NEW.processing_at IS NULL AND NEW.cancelled_by IS NULL AND NEW.cancelled_at IS NULL AND NEW.cancellation_reason IS NULL)
                OR (((OLD.department = 'laboratory' AND OLD.status = 'collected') OR (OLD.department = 'radiology' AND OLD.status = 'requested'))
                    AND NEW.status = 'processing' AND {$collectionSame} AND NEW.processing_by IS NOT NULL AND NEW.processing_at = NEW.last_event_at
                    AND NEW.cancelled_by IS NULL AND NEW.cancelled_at IS NULL AND NEW.cancellation_reason IS NULL)
                OR (OLD.status IN ('requested','collected') AND NEW.status = 'cancelled' AND {$collectionSame}
                    AND NEW.processing_by IS NULL AND NEW.processing_at IS NULL AND NEW.cancelled_by IS NOT NULL AND NEW.cancelled_at = NEW.last_event_at
                    AND {$length}(trim(NEW.cancellation_reason)) BETWEEN 5 AND 2000))) IS NOT TRUE");
        $this->guard('investigation_revisions', 'insert', 'investigation_revision_sequence', "(NEW.version > 0
            AND NEW.operation IN ('requested','collected','processing','cancelled') AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM investigation_orders o WHERE o.id = NEW.order_id AND o.version = NEW.version AND o.last_changed_at = NEW.recorded_at)
            AND NEW.version = (SELECT COALESCE(MAX(r.version),0) + 1 FROM investigation_revisions r WHERE r.order_id = NEW.order_id)
            AND ((NEW.version = 1 AND NEW.operation = 'requested' AND NEW.item_id IS NULL) OR
                (NEW.version > 1 AND NEW.item_id IS NOT NULL AND EXISTS (SELECT 1 FROM investigation_items i WHERE i.id = NEW.item_id AND i.order_id = NEW.order_id AND i.status = NEW.operation)))) IS NOT TRUE");
        $this->guard('investigation_revisions', 'update', 'investigation_revision_immutable', '1 = 1');
        foreach (['investigation_orders', 'investigation_items', 'investigation_revisions'] as $table) {
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
        }
        $this->guard('encounters', 'update', 'encounter_pending_investigations', "NEW.status IN ('closed','cancelled') AND EXISTS
            (SELECT 1 FROM investigation_items i WHERE i.encounter_id = OLD.id AND i.status IN ('requested','collected','processing'))");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS encounter_pending_investigations');
        Schema::dropIfExists('investigation_revisions');
        Schema::dropIfExists('investigation_items');
        Schema::dropIfExists('investigation_orders');
    }

    private function same(string $first, string $second): string
    {
        return DB::getDriverName() === 'mysql' ? "({$first} <=> {$second})" : "({$first} IS {$second})";
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Investigation request integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Investigation request integrity violation'); END");
        }
    }
};
