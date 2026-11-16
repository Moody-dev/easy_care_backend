<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investigation_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained('investigation_items')->restrictOnDelete();
            $table->foreignId('test_id')->constrained('investigation_tests')->restrictOnDelete();
            $table->foreignId('schema_id')->constrained('investigation_schemas')->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreign(['item_id', 'test_id', 'schema_id', 'encounter_id'], 'result_requested_identity')
                ->references(['id', 'test_id', 'schema_id', 'encounter_id'])->on('investigation_items')->restrictOnDelete();
            $table->foreignId('previous_result_id')->nullable()->constrained('investigation_results')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('draft');
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->json('fields');
            $table->mediumText('comment')->nullable();
            $table->text('reason');
            $table->dateTime('last_changed_at', 6);
            $table->dateTime('submitted_at', 6)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('verified_at', 6)->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('released_at', 6)->nullable();
            $table->dateTime('voided_at', 6)->nullable();
            $table->timestamps(6);
            $table->unsignedBigInteger('working_item_id')->nullable()->storedAs("CASE WHEN status IN ('draft','submitted','verified') THEN item_id ELSE NULL END");
            $table->unique('working_item_id');
            $table->unique(['item_id', 'sequence']);
            $table->index(['status', 'id']);
        });
        Schema::create('investigation_result_file_bindings', function (Blueprint $table): void {
            $table->foreignId('file_id')->primary()->constrained('files')->restrictOnDelete();
            $table->foreignId('item_id')->constrained('investigation_items')->restrictOnDelete();
        });
        Schema::create('investigation_result_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('result_id')->constrained('investigation_results')->restrictOnDelete();
            $table->foreignId('file_id')->constrained('files')->restrictOnDelete();
            $table->string('original_name', 191);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->unique(['result_id', 'file_id']);
        });
        Schema::create('investigation_result_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('result_id')->constrained('investigation_results')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('operation', 16);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->text('reason');
            $table->json('snapshot');
            $table->unique(['result_id', 'version']);
        });
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $open = "EXISTS (SELECT 1 FROM encounters e JOIN patients p ON p.id = e.patient_id WHERE e.id = NEW.encounter_id
            AND e.status IN ('open','in_progress') AND p.access_status = 'active' AND NEW.last_changed_at >= e.opened_at)";
        $this->guard('investigation_results', 'insert', 'investigation_result_initial', "(NEW.version = 1 AND NEW.status = 'draft'
            AND NEW.submitted_at IS NULL AND NEW.verified_by IS NULL AND NEW.verified_at IS NULL AND NEW.released_by IS NULL
            AND NEW.released_at IS NULL AND NEW.voided_at IS NULL AND NEW.created_at = NEW.last_changed_at
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND {$open}
            AND EXISTS (SELECT 1 FROM investigation_items i WHERE i.id = NEW.item_id AND i.status IN ('processing','completed') AND NEW.last_changed_at >= i.last_event_at)
            AND NEW.sequence = (SELECT COALESCE(MAX(r.sequence),0) + 1 FROM investigation_results r WHERE r.item_id = NEW.item_id)
            AND NOT EXISTS (SELECT 1 FROM investigation_results r WHERE r.item_id = NEW.item_id AND r.last_changed_at > NEW.last_changed_at)
            AND ((NEW.previous_result_id IS NULL AND NOT EXISTS (SELECT 1 FROM investigation_results r WHERE r.item_id = NEW.item_id AND r.status = 'released'))
                OR EXISTS (SELECT 1 FROM investigation_results r WHERE r.id = NEW.previous_result_id AND r.item_id = NEW.item_id AND r.status = 'released'
                    AND r.released_at <= NEW.last_changed_at AND NOT EXISTS (SELECT 1 FROM investigation_results n WHERE n.previous_result_id = r.id AND n.status = 'released')))) IS NOT TRUE");
        $identity = 'NEW.id = OLD.id AND NEW.item_id = OLD.item_id AND NEW.test_id = OLD.test_id AND NEW.schema_id = OLD.schema_id
            AND NEW.encounter_id = OLD.encounter_id AND '.$this->same('NEW.previous_result_id', 'OLD.previous_result_id').'
            AND NEW.sequence = OLD.sequence AND NEW.author_id = OLD.author_id AND NEW.created_at = OLD.created_at
            AND NEW.version = OLD.version + 1 AND NEW.last_changed_at >= OLD.last_changed_at';
        $contentSame = $this->same('NEW.fields', 'OLD.fields').' AND '.$this->same('NEW.comment', 'OLD.comment').' AND NEW.reason = OLD.reason';
        $this->guard('investigation_results', 'update', 'investigation_result_lifecycle', "({$identity} AND {$open}
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND (
                (OLD.status = 'draft' AND NEW.status IN ('draft','submitted','voided') AND NEW.verified_by IS NULL AND NEW.verified_at IS NULL
                    AND NEW.released_by IS NULL AND NEW.released_at IS NULL
                    AND ((NEW.status = 'draft' AND NEW.submitted_at IS NULL AND NEW.voided_at IS NULL)
                        OR (NEW.status = 'submitted' AND NEW.submitted_at = NEW.last_changed_at AND NEW.voided_at IS NULL AND {$contentSame})
                        OR (NEW.status = 'voided' AND NEW.voided_at = NEW.last_changed_at AND NEW.submitted_at IS NULL AND {$contentSame})))
                OR (OLD.status = 'submitted' AND NEW.status IN ('verified','voided') AND {$contentSame}
                    AND NEW.submitted_at = OLD.submitted_at AND NEW.released_by IS NULL AND NEW.released_at IS NULL
                    AND ((NEW.status = 'verified' AND NEW.verified_by <> NEW.author_id AND NEW.verified_at = NEW.last_changed_at AND NEW.voided_at IS NULL)
                        OR (NEW.status = 'voided' AND NEW.verified_by IS NULL AND NEW.verified_at IS NULL AND NEW.voided_at = NEW.last_changed_at)))
                OR (OLD.status = 'verified' AND NEW.status IN ('released','voided') AND {$contentSame}
                    AND NEW.submitted_at = OLD.submitted_at AND NEW.verified_by = OLD.verified_by AND NEW.verified_at = OLD.verified_at
                    AND ((NEW.status = 'released' AND NEW.released_by = OLD.verified_by AND NEW.released_by <> NEW.author_id
                            AND NEW.released_at = NEW.last_changed_at AND NEW.voided_at IS NULL
                            AND NOT EXISTS (SELECT 1 FROM investigation_results n WHERE n.item_id = OLD.item_id AND n.status = 'released'
                                AND n.sequence > COALESCE((SELECT p.sequence FROM investigation_results p WHERE p.id = OLD.previous_result_id),0)))
                        OR (NEW.status = 'voided' AND NEW.released_by IS NULL AND NEW.released_at IS NULL AND NEW.voided_at = NEW.last_changed_at))))) IS NOT TRUE");
        $this->guard('investigation_result_attachments', 'insert', 'investigation_attachment_initial', "(EXISTS
            (SELECT 1 FROM investigation_results r JOIN files f ON f.id = NEW.file_id
                JOIN investigation_result_file_bindings b ON b.file_id = f.id AND b.item_id = r.item_id WHERE r.id = NEW.result_id AND r.status = 'draft'
                AND f.uploaded_by = r.author_id AND f.scan_status IN ('not_scanned','clean')
                AND f.original_name = NEW.original_name AND f.mime_type = NEW.mime_type AND f.byte_size = NEW.byte_size AND f.sha256 = NEW.sha256)
            AND (SELECT COUNT(*) FROM investigation_result_attachments a WHERE a.result_id = NEW.result_id) < 10) IS NOT TRUE");
        $this->guard('investigation_result_attachments', 'delete', 'investigation_attachment_remove', "NOT EXISTS
            (SELECT 1 FROM investigation_results r WHERE r.id = OLD.result_id AND r.status = 'draft')");
        $this->guard('investigation_result_attachments', 'update', 'investigation_attachment_immutable', '1 = 1');
        $this->guard('investigation_result_file_bindings', 'update', 'investigation_file_binding_immutable', '1 = 1');
        $this->guard('investigation_result_file_bindings', 'delete', 'investigation_file_binding_retain', '1 = 1');
        $this->guard('investigation_result_revisions', 'insert', 'investigation_result_revision_sequence', "(NEW.operation IN ('created','updated','submitted','verified','released','voided','amended')
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000
            AND EXISTS (SELECT 1 FROM investigation_results r WHERE r.id = NEW.result_id AND r.version = NEW.version AND r.last_changed_at = NEW.recorded_at)
            AND NEW.version = (SELECT COALESCE(MAX(h.version),0) + 1 FROM investigation_result_revisions h WHERE h.result_id = NEW.result_id)) IS NOT TRUE");
        $this->guard('investigation_result_revisions', 'update', 'investigation_result_revision_immutable', '1 = 1');
        foreach (['investigation_results', 'investigation_result_revisions'] as $table) {
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
        }
        (require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php'))->installGuards(true);
    }

    public function down(): void
    {
        (require database_path('migrations/2026_11_16_023634_create_investigation_request_records.php'))->installGuards();
        Schema::dropIfExists('investigation_result_revisions');
        Schema::dropIfExists('investigation_result_attachments');
        Schema::dropIfExists('investigation_result_file_bindings');
        Schema::dropIfExists('investigation_results');
    }

    private function same(string $first, string $second): string
    {
        return DB::getDriverName() === 'mysql' ? "({$first} <=> {$second})" : "({$first} IS {$second})";
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Investigation result integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Investigation result integrity violation'); END");
        }
    }
};
