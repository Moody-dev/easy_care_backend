<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->string('category', 64);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
        });
        Schema::create('investigation_tests', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 191);
            $table->string('department', 32);
            $table->string('specimen', 191)->nullable();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps(6);
            $table->index(['department', 'is_active', 'name']);
        });
        Schema::create('investigation_schemas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('test_id')->constrained('investigation_tests')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema_json');
            $table->char('content_hash', 64);
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('published_at', 6);
            $table->text('approval_reference');
            $table->text('reason');
            $table->unique(['test_id', 'version']);
            $table->unique(['id', 'test_id']);
        });
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $this->guard('services', 'insert', 'services_initial', "(NEW.category IN ('laboratory','radiology') AND NEW.is_active = 1
            AND {$length}(trim(NEW.code)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191) IS NOT TRUE");
        $this->guard('services', 'update', 'services_identity', '1 = 1');
        $this->guard('investigation_tests', 'insert', 'investigation_initial', "(NEW.version = 1
            AND NEW.department IN ('laboratory','radiology') AND NEW.is_active IN (0,1)
            AND {$length}(trim(NEW.code)) BETWEEN 1 AND 64 AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191
            AND EXISTS (SELECT 1 FROM services s WHERE s.id = NEW.service_id AND s.category = NEW.department AND s.is_active = 1)) IS NOT TRUE");
        $codeEqual = DB::getDriverName() === 'mysql' ? 'BINARY NEW.code = BINARY OLD.code' : 'NEW.code = OLD.code COLLATE BINARY';
        $this->guard('investigation_tests', 'update', 'investigation_version', "(NEW.id = OLD.id AND {$codeEqual}
            AND NEW.department = OLD.department AND NEW.service_id = OLD.service_id AND NEW.created_by = OLD.created_by
            AND NEW.created_at = OLD.created_at AND NEW.version = OLD.version + 1 AND NEW.is_active IN (0,1)
            AND {$length}(trim(NEW.name)) BETWEEN 1 AND 191
            AND EXISTS (SELECT 1 FROM investigation_schemas s WHERE s.test_id = OLD.id AND s.version = OLD.version)) IS NOT TRUE");
        $this->guard('investigation_schemas', 'insert', 'investigation_publication', "(NEW.version > 0
            AND EXISTS (SELECT 1 FROM investigation_tests t WHERE t.id = NEW.test_id AND t.version = NEW.version)
            AND NEW.version = (SELECT COALESCE(MAX(s.version),0) + 1 FROM investigation_schemas s WHERE s.test_id = NEW.test_id)
            AND {$length}(NEW.content_hash) = 64 AND {$length}(trim(NEW.approval_reference)) BETWEEN 5 AND 2000
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000) IS NOT TRUE");
        $this->guard('investigation_schemas', 'update', 'investigation_schema_immutable', '1 = 1');
        foreach (['services', 'investigation_tests', 'investigation_schemas'] as $table) {
            $this->guard($table, 'delete', $table.'_retain', '1 = 1');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_schemas');
        Schema::dropIfExists('investigation_tests');
        Schema::dropIfExists('services');
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN
                IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Investigation catalog integrity violation'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, 'Investigation catalog integrity violation'); END");
        }
    }
};
