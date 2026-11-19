<?php

use App\Services\PostgresSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PostgresSchema::class)->install();
    }

    public function down(): void
    {
        app(PostgresSchema::class)->removeEmpty();
    }
};
