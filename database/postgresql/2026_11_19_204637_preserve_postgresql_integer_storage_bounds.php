<?php

use App\Services\PostgresSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->constraints(false);
    }

    public function down(): void
    {
        foreach (app(PostgresSchema::class)->manifest()['tables'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained testing records prevent removal of their storage bounds.');
            }
        }
        $this->constraints(true);
    }

    private function constraints(bool $remove): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach (app(PostgresSchema::class)->manifest()['integer_ranges'] as $table => $columns) {
            $clauses = [];
            foreach ($columns as $column => [$minimum, $maximum]) {
                $name = 'storage_range_'.substr(hash('sha256', $table.'.'.$column), 0, 20);
                $clauses[] = $remove ? 'DROP CONSTRAINT '.$grammar->wrap($name)
                    : 'ADD CONSTRAINT '.$grammar->wrap($name).' CHECK ('.$grammar->wrap($column).' BETWEEN '.$minimum.' AND '.$maximum.')';
            }
            DB::statement('ALTER TABLE '.$grammar->wrap($table).' '.implode(', ', $clauses));
        }
    }
};
