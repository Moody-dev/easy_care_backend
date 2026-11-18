<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RETAINED = ['reminder_revisions','reminder_recipients','reminder_occurrences','reminder_deliveries','reminder_events'];

    public function up(): void
    {
        Schema::create('staff_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained('encounters')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('next_sequence')->default(1);
            $table->dateTime('next_due_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['is_active','next_due_at','id'], 'reminder_due_work');
        });
        Schema::create('reminder_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('staff_reminders')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->text('content');
            $table->dateTime('starts_at', 6);
            $table->string('timezone', 40);
            $table->string('frequency', 16);
            $table->unsignedSmallInteger('interval');
            $table->unsignedSmallInteger('occurrence_count');
            $table->dateTime('ends_at', 6)->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at', 6);
            $table->unique(['reminder_id','version']);
        });
        Schema::create('reminder_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('reminder_revisions')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unique(['revision_id','user_id']);
            $table->index(['user_id','revision_id']);
        });
        Schema::create('reminder_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('staff_reminders')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('reminder_revisions')->restrictOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->dateTime('due_at', 6);
            $table->dateTime('processed_at', 6);
            $table->unique(['revision_id','sequence']);
        });
        Schema::create('reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occurrence_id')->constrained('reminder_occurrences')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->string('outcome', 16);
            $table->string('suppression_code', 40)->nullable();
            $table->dateTime('recorded_at', 6);
            $table->unique(['occurrence_id','recipient_id']);
        });
        Schema::create('staff_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->unique()->constrained('reminder_deliveries')->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('available_at', 6);
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['recipient_id','read_at','available_at'], 'staff_inbox_unread');
        });
        Schema::create('reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('staff_reminders')->restrictOnDelete();
            $table->foreignId('revision_id')->constrained('reminder_revisions')->restrictOnDelete();
            $table->foreignId('notification_id')->nullable()->constrained('staff_notifications')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 24);
            $table->unsignedInteger('version');
            $table->dateTime('recorded_at', 6);
            $table->index(['reminder_id','id']);
        });
        foreach (self::RETAINED as $table) {
            $this->guard($table, 'update', $table.'_retain_update', '1=1');
            $this->guard($table, 'delete', $table.'_retain_delete', '1=1');
        }
        foreach (['staff_reminders','staff_notifications'] as $table) {
            $this->guard($table, 'delete', $table.'_retain_delete', '1=1');
        }
        $this->guard('staff_reminders', 'insert', 'reminder_context', "NEW.version<>1 OR NEW.is_active<>1 OR NEW.next_sequence<>1 OR NEW.next_due_at IS NULL
            OR (NEW.encounter_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM encounters e WHERE e.id=NEW.encounter_id AND e.patient_id=NEW.patient_id))");
        $this->guard('staff_reminders', 'update', 'reminder_identity', 'NEW.id<>OLD.id OR NEW.owner_id<>OLD.owner_id OR NOT (NEW.patient_id <=> OLD.patient_id)
            OR NOT (NEW.encounter_id <=> OLD.encounter_id) OR NEW.created_at<>OLD.created_at OR NEW.updated_at<OLD.updated_at
            OR NEW.version NOT IN (OLD.version,OLD.version+1) OR NEW.next_sequence<1 OR NEW.next_sequence>367
            OR NEW.is_active NOT IN (0,1) OR (OLD.is_active=0 AND NEW.is_active<>0)
            OR (NEW.version=OLD.version AND (OLD.is_active<>1 OR NEW.is_active<>OLD.is_active OR NEW.next_sequence<>OLD.next_sequence+1
                OR NOT EXISTS (SELECT 1 FROM reminder_occurrences o JOIN reminder_revisions v ON v.id=o.revision_id
                    WHERE o.reminder_id=OLD.id AND v.version=OLD.version AND o.sequence=OLD.next_sequence)))
            OR (NEW.version=OLD.version+1 AND (NEW.next_sequence<>1 OR NOT EXISTS (
                SELECT 1 FROM reminder_revisions v WHERE v.reminder_id=NEW.id AND v.version=NEW.version)))');
        $this->guard('reminder_revisions', 'insert', 'reminder_schedule', "NEW.version<1 OR NEW.timezone<>'Africa/Lagos'
            OR NEW.frequency NOT IN ('once','daily','weekly','monthly') OR NEW.interval NOT BETWEEN 1 AND 365
            OR NEW.occurrence_count NOT BETWEEN 1 AND 366 OR (NEW.frequency='once' AND (NEW.interval<>1 OR NEW.occurrence_count<>1))
            OR (NEW.ends_at IS NOT NULL AND NEW.ends_at<NEW.starts_at)
            OR NOT EXISTS (SELECT 1 FROM staff_reminders r WHERE r.id=NEW.reminder_id AND r.owner_id=NEW.actor_id)");
        $this->guard('reminder_occurrences', 'insert', 'reminder_occurrence_scope', 'NEW.sequence NOT BETWEEN 1 AND 366 OR NEW.processed_at<NEW.due_at
            OR NOT EXISTS (SELECT 1 FROM reminder_revisions v WHERE v.id=NEW.revision_id AND v.reminder_id=NEW.reminder_id AND NEW.sequence<=v.occurrence_count)');
        $this->guard('reminder_deliveries', 'insert', 'reminder_delivery_scope', "NEW.outcome NOT IN ('delivered','suppressed')
            OR (NEW.outcome='delivered' AND NEW.suppression_code IS NOT NULL)
            OR (NEW.outcome='suppressed' AND NEW.suppression_code IS NULL)
            OR NOT EXISTS (SELECT 1 FROM reminder_occurrences o JOIN reminder_recipients p ON p.revision_id=o.revision_id
                WHERE o.id=NEW.occurrence_id AND p.user_id=NEW.recipient_id)");
        $this->guard('staff_notifications', 'insert', 'notification_delivery_scope', "NEW.version<>1 OR NEW.read_at IS NOT NULL
            OR NOT EXISTS (SELECT 1 FROM reminder_deliveries d WHERE d.id=NEW.delivery_id AND d.recipient_id=NEW.recipient_id AND d.outcome='delivered')");
        $this->guard('staff_notifications', 'update', 'notification_identity', 'NEW.id<>OLD.id OR NEW.delivery_id<>OLD.delivery_id OR NEW.recipient_id<>OLD.recipient_id
            OR NEW.created_at<>OLD.created_at OR NEW.version<>OLD.version+1 OR NEW.updated_at<OLD.updated_at
            OR (NEW.read_at IS NOT NULL AND (NEW.read_at<NEW.created_at OR NEW.read_at>NEW.updated_at))');
        $this->guard('reminder_events', 'insert', 'reminder_event_scope', "NEW.action NOT IN ('created','updated','cancelled','delivered','suppressed','read','unread','snoozed')
            OR NOT EXISTS (SELECT 1 FROM reminder_revisions v WHERE v.id=NEW.revision_id AND v.reminder_id=NEW.reminder_id)
            OR (NEW.notification_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM staff_notifications n JOIN reminder_deliveries d ON d.id=n.delivery_id
                JOIN reminder_occurrences o ON o.id=d.occurrence_id WHERE n.id=NEW.notification_id AND o.reminder_id=NEW.reminder_id AND o.revision_id=NEW.revision_id))");
    }

    public function down(): void
    {
        if (DB::table('staff_reminders')->exists()) {
            throw new RuntimeException('Cannot discard retained staff reminders or notifications. Preserve these records before rollback.');
        }
        foreach (['reminder_events','staff_notifications','reminder_deliveries','reminder_occurrences','reminder_recipients','reminder_revisions','staff_reminders'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function guard(string $table, string $event, string $name, string $condition): void
    {
        if (DB::getDriverName()==='mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition}
                THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reminder integrity violation'; END IF; END");
        } else {
            $condition=str_replace(['NEW.patient_id <=> OLD.patient_id','NEW.encounter_id <=> OLD.encounter_id'],
                ['NEW.patient_id IS OLD.patient_id','NEW.encounter_id IS OLD.encounter_id'], $condition);
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} WHEN {$condition}
                BEGIN SELECT RAISE(ABORT,'Reminder integrity violation'); END");
        }
    }
};
