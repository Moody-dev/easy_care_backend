<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostgresSchema
{
    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return json_decode(file_get_contents(database_path('schema/postgres-testing.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function assertCurrent(): void
    {
        $manifest = $this->manifest();
        $files = glob(database_path('migrations/*.php'));
        sort($files, SORT_STRING);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[basename($file)] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($file)));
        }
        if ($hashes !== $manifest['migrations'] || hash('sha256', str_replace("\r\n", "\n", file_get_contents(database_path('schema/postgres-testing.sql')))) !== $manifest['sql_sha256']) {
            throw new RuntimeException('The PostgreSQL testing baseline must be reviewed against the current MySQL migrations before use.');
        }
    }

    public function install(): void
    {
        $this->assertCurrent();
        if (DB::getDriverName() !== 'pgsql' || DB::connection()->getConfig('search_path') !== ['easycare', 'extensions']) {
            throw new RuntimeException('PostgreSQL testing tables require the private easycare schema.');
        }
        $schema = DB::selectOne('SELECT current_schema() AS name');
        if ($schema?->name !== 'easycare') {
            throw new RuntimeException('Create the private easycare schema for the database user before migrating.');
        }
        DB::statement('CREATE SCHEMA IF NOT EXISTS extensions');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext WITH SCHEMA extensions');
        $extension = DB::selectOne("SELECT n.nspname FROM pg_extension e JOIN pg_namespace n ON n.oid = e.extnamespace WHERE e.extname = 'citext'");
        if ($extension->nspname !== 'extensions') {
            throw new RuntimeException('The testing setup requires citext in the extensions schema. Review the existing extension installation before proceeding.');
        }
        $type = DB::connection()->getQueryGrammar()->wrap($extension->nspname.'.citext');
        $sql = str_replace('__CITEXT_TYPE__', $type, file_get_contents(database_path('schema/postgres-testing.sql')));
        try {
            DB::unprepared($sql);
        } catch (\Illuminate\Database\QueryException $exception) {
            throw new RuntimeException('PostgreSQL schema installation failed: '.substr($exception->errorInfo[2] ?? 'database error', 0, 1000));
        }
        $this->restrictAccess();
    }

    public function restrictAccess(): void
    {
        $roles = ['PUBLIC'];
        foreach (['anon', 'authenticated', 'service_role'] as $role) {
            if (DB::selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$role]) !== null) {
                $roles[] = DB::connection()->getQueryGrammar()->wrap($role);
            }
        }
        $grantees = implode(', ', $roles);
        foreach (['SCHEMA easycare', 'ALL TABLES IN SCHEMA easycare', 'ALL SEQUENCES IN SCHEMA easycare', 'ALL FUNCTIONS IN SCHEMA easycare'] as $object) {
            DB::statement('REVOKE ALL PRIVILEGES ON '.$object.' FROM '.$grantees);
        }
    }

    public function removeEmpty(): void
    {
        $manifest = $this->manifest();
        foreach ($manifest['tables'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Retained PostgreSQL testing records cannot be removed by migration rollback.');
            }
        }
        foreach ($manifest['views'] as $view) {
            DB::statement('DROP VIEW IF EXISTS '.DB::connection()->getQueryGrammar()->wrap($view));
        }
        foreach (array_reverse($manifest['tables']) as $table) {
            DB::statement('DROP TABLE IF EXISTS '.DB::connection()->getQueryGrammar()->wrap($table).' CASCADE');
        }
        foreach (DB::select("SELECT proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'easycare' AND prorettype = 'trigger'::regtype") as $function) {
            DB::statement('DROP FUNCTION '.DB::connection()->getQueryGrammar()->wrap($function->proname).'()');
        }
    }
}
