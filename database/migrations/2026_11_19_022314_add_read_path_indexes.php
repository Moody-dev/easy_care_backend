<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void { $table->index(['access_status','last_name','id'],'patients_visible_name_order'); });
        Schema::table('appointments', function (Blueprint $table): void { $table->index(['starts_at','id'],'appointments_start_order'); });
        Schema::table('encounters', function (Blueprint $table): void { $table->index(['opened_at','id'],'encounters_opened_order'); });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void { $table->dropIndex('encounters_opened_order'); });
        Schema::table('appointments', function (Blueprint $table): void { $table->dropIndex('appointments_start_order'); });
        Schema::table('patients', function (Blueprint $table): void { $table->dropIndex('patients_visible_name_order'); });
    }
};
