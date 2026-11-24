<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_insurance_intakes', function (Blueprint $table): void {
            $table->foreignId('patient_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('hmo_plans')->restrictOnDelete();
            $table->string('member_number', 128);
            $table->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('captured_at', 6);
            $table->foreignId('coverage_id')->nullable()->unique()->constrained('hmo_coverages')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_insurance_intakes');
    }
};
