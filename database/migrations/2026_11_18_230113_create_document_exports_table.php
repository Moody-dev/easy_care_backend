<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 24);
            $table->string('format', 8);
            $table->longText('bundle');
            $table->unsignedInteger('byte_size');
            $table->char('sha256', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('expires_at', 6);
            $table->index(['creator_id', 'expires_at']);
        });
        foreach (['update','delete'] as $event) {
            if (DB::getDriverName()==='mysql') {
                DB::unprepared("CREATE TRIGGER document_exports_retain_{$event} BEFORE {$event} ON document_exports FOR EACH ROW
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Generated documents are immutable'");
            } else {
                DB::unprepared("CREATE TRIGGER document_exports_retain_{$event} BEFORE {$event} ON document_exports
                    BEGIN SELECT RAISE(ABORT,'Generated documents are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        if (DB::table('document_exports')->exists()) { throw new RuntimeException('Preserve generated documents before rollback.'); }
        Schema::dropIfExists('document_exports');
    }
};
