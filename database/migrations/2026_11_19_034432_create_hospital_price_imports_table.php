<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_price_imports', function (Blueprint $table): void {
            $table->id();
            $table->char('content_hash', 64)->unique();
            $table->longText('sources');
            $table->dateTime('effective_at', 6);
            $table->dateTime('created_at', 6);
        });
        Schema::create('hospital_reference_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_id')->constrained('hospital_price_imports')->restrictOnDelete();
            $table->string('source_kind', 24);
            $table->string('source_file', 96);
            $table->unsignedInteger('source_line');
            $table->text('group_name');
            $table->text('label');
            $table->char('item_key', 64);
            $table->unsignedBigInteger('amount_micros')->nullable();
            $table->char('currency', 3);
            $table->string('basis', 24);
            $table->string('status', 24);
            $table->json('issues');
            $table->json('raw_cells');
            $table->unsignedInteger('duplicate_line')->nullable();
            $table->foreignId('bed_id')->nullable()->constrained('beds')->restrictOnDelete();
            $table->unique(['import_id', 'source_file', 'source_line'], 'hospital_price_source_row');
            $table->index(['import_id', 'source_kind', 'status'], 'hospital_price_review');
        });
        foreach (['hospital_price_imports', 'hospital_reference_prices'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $name = $table.'_'.strtolower($event);
                DB::unprepared(DB::getDriverName() === 'mysql'
                    ? "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Price source records are retained'"
                    : "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} BEGIN SELECT RAISE(ABORT, 'Price source records are retained'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('hospital_price_imports')->exists()) {
            throw new RuntimeException('Retained hospital price sources must not be removed by rollback.');
        }
        Schema::dropIfExists('hospital_reference_prices');
        Schema::dropIfExists('hospital_price_imports');
    }
};
