<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admission_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('workflow_version');
            $table->string('operation', 32);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('snapshot');
            $table->dateTime('recorded_at', 6);
            $table->unique(['admission_id', 'workflow_version']);
        });
        $length = DB::getDriverName() === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $id = $this->number('id');
        $version = $this->number('workflow_version');
        $this->trigger('insert', 'admission_revisions_validate', "(NEW.workflow_version BETWEEN 1 AND 4294967294
            AND NEW.operation IN ('admitted', 'allocated', 'transferred', 'released', 'discharge_authorized', 'discharged')
            AND {$length}(trim(NEW.reason)) BETWEEN 5 AND 2000 AND {$id} = NEW.admission_id
            AND {$version} = NEW.workflow_version
            AND NEW.workflow_version = COALESCE((SELECT MAX(r.workflow_version) FROM admission_revisions r
                WHERE r.admission_id = NEW.admission_id), 0) + 1) IS NOT TRUE", 'Invalid admission history snapshot.');
        foreach (['update', 'delete'] as $operation) {
            $this->trigger($operation, 'admission_revisions_no_'.$operation, '1 = 1', 'Admission history is append-only.');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_revisions');
    }

    private function number(string $field): string
    {
        return DB::getDriverName() === 'mysql'
            ? "CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.snapshot, '$.{$field}')) AS UNSIGNED)"
            : "json_extract(NEW.snapshot, '$.{$field}')";
    }

    private function trigger(string $operation, string $name, string $condition, string $message): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON admission_revisions FOR EACH ROW
                BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON admission_revisions WHEN {$condition}
                BEGIN SELECT RAISE(ABORT, '{$message}'); END");
        }
    }
};
