<?php

namespace App\Console\Commands;

use App\Services\SupabaseSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

#[Signature('supabase:prepare')]
#[Description('Prepare the private PostgreSQL testing schema and seed reference permissions only')]
class PrepareSupabaseTesting extends Command
{
    public function handle(SupabaseSetup $setup): int
    {
        if (in_array(false, $setup->run(), true)) {
            $this->error('Complete the private PostgreSQL connection settings and pass supabase:check first.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function (): void {
                DB::statement('CREATE SCHEMA IF NOT EXISTS easycare AUTHORIZATION CURRENT_USER');
                DB::select("SELECT pg_advisory_xact_lock(hashtextextended('easycare_schema_setup', 0))");
                $schema = DB::getSchemaBuilder();
                if (! $schema->hasTable('migrations') && $schema->getTables('easycare') !== []) {
                    throw new RuntimeException('The private schema already contains unrelated tables.');
                }
                if ($this->callSilent('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS
                    || $this->callSilent('db:seed', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                    throw new RuntimeException('Testing database preparation failed.');
                }
            });
        } catch (Throwable) {
            $this->error('Testing database preparation failed. Check the private credentials, TLS certificate and schema permissions. No connection details were printed.');

            return self::FAILURE;
        }
        $this->info('Private testing schema prepared. Reference permissions are seeded; no staff, patients, prices or stock were copied.');

        return self::SUCCESS;
    }
}
