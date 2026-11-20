<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_number_sequences', function (Blueprint $table): void {
            $table->string('prefix', 3)->primary();
            $table->unsignedBigInteger('last_value')->default(0);
        });
        DB::table('patient_number_sequences')->insert([['prefix' => 'PVT', 'last_value' => 0], ['prefix' => 'WLK', 'last_value' => 0]]);
        Schema::create('patient_number_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('patient_categories')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('number', 64)->unique();
            $table->char('request_key', 64);
            $table->char('token_hash', 64)->unique();
            $table->text('encrypted_token');
            $table->foreignId('patient_id')->nullable()->unique()->constrained('patients')->restrictOnDelete();
            $table->dateTime('consumed_at', 6)->nullable();
            $table->timestamps(6);
            $table->unique(['requested_by', 'request_key'], 'patient_number_reservation_retry');
        });
        $this->guard('patient_number_sequences', 'insert', 'number_sequence_valid',
            "NEW.prefix NOT IN ('PVT','WLK') OR NEW.last_value < 0 OR NEW.last_value > 999999999999999999");
        $this->guard('patient_number_sequences', 'update', 'number_sequence_monotonic',
            'NEW.prefix <> OLD.prefix OR NEW.last_value < OLD.last_value OR NEW.last_value > 999999999999999999');
        foreach (['insert', 'update'] as $event) {
            $this->guard('patient_number_reservations', $event, 'number_reservation_valid_'.$event,
                "((NEW.patient_id IS NULL) <> (NEW.consumed_at IS NULL))
                OR NOT EXISTS (SELECT 1 FROM patient_categories c WHERE c.id = NEW.category_id
                    AND ((c.code = 'private' AND NEW.number LIKE 'PVT-%') OR (c.code = 'walk-in' AND NEW.number LIKE 'WLK-%')))
                OR (NEW.patient_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM patients p WHERE p.id = NEW.patient_id AND p.mrn = NEW.number))
                OR length(NEW.token_hash) <> 64 OR length(NEW.request_key) <> 64 OR length(NEW.encrypted_token) < 1
                OR NEW.created_at IS NULL OR NEW.updated_at IS NULL");
        }
        $this->guard('patient_number_reservations', 'update', 'number_reservation_immutable',
            'NEW.category_id <> OLD.category_id OR NEW.requested_by <> OLD.requested_by OR NEW.number <> OLD.number
                OR NEW.request_key <> OLD.request_key OR NEW.token_hash <> OLD.token_hash OR NEW.encrypted_token <> OLD.encrypted_token
                OR NEW.created_at <> OLD.created_at OR OLD.patient_id IS NOT NULL');
        $this->guard('patient_number_reservations', 'delete', 'number_reservation_no_delete', '1 = 1');
        $this->guard('patient_number_sequences', 'delete', 'number_sequence_no_delete', '1 = 1');
        if (DB::getDriverName() === 'pgsql') {
            app(\App\Services\PostgresSchema::class)->restrictAccess();
        }
    }

    public function down(): void
    {
        if (DB::table('patient_number_reservations')->exists()) {
            throw new RuntimeException('Retained patient number reservations cannot be removed.');
        }
        Schema::dropIfExists('patient_number_reservations');
        Schema::dropIfExists('patient_number_sequences');
        if (DB::getDriverName() === 'pgsql') {
            foreach (['number_sequence_valid', 'number_sequence_monotonic', 'number_reservation_valid_insert',
                'number_reservation_valid_update', 'number_reservation_immutable', 'number_reservation_no_delete', 'number_sequence_no_delete'] as $name) {
                DB::statement('DROP FUNCTION IF EXISTS '.$name.'()');
            }
        }
    }

    private function guard(string $table, string $event, string $name, string $invalid): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$invalid} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Patient number history must be preserved.'; END IF; END");
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE OR REPLACE FUNCTION {$name}() RETURNS trigger LANGUAGE plpgsql AS \$guard\$ BEGIN IF {$invalid} THEN RAISE EXCEPTION 'Patient number history must be preserved.'; END IF; RETURN NEW; END; \$guard\$;
                CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW EXECUTE FUNCTION {$name}();");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Patient number history must be preserved.'); END");
        }
    }
};
