<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Throwable;

trait CommittedDatabase
{
    use DatabaseMigrations { runDatabaseMigrations as private rebuildTestDatabase; }

    public function runDatabaseMigrations(): void
    {
        $this->ensureDatabaseIsIsolated($this->app);
        if (!in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) { $this->rebuildTestDatabase(); return; }
        if (!RefreshDatabaseState::$migrated) {
            $this->refreshTestDatabase();
            $this->app[Kernel::class]->setArtisan(null);
            RefreshDatabaseState::$migrated=true;
        }
        $this->clearCommittedTestData();
        $this->beforeApplicationDestroyed(function (): void {
            try { $this->clearCommittedTestData(); }
            catch (Throwable $exception) { RefreshDatabaseState::$migrated=false; throw $exception; }
        });
    }

    protected function clearCommittedTestData(): void
    {
        $this->ensureDatabaseIsIsolated($this->app);
        $connection=DB::connection();
        if (!in_array($connection->getDriverName(), ['mysql', 'pgsql'], true)) { throw new \RuntimeException('Committed fixture cleanup requires an isolated server database.'); }
        if ($connection->transactionLevel()!==0) { throw new \RuntimeException('Finish the test transaction before clearing committed fixtures.'); }
        if ($connection->getDriverName() === 'pgsql') {
            $tables = array_filter($connection->getSchemaBuilder()->getTables('easycare'), fn (array $table): bool => $table['name'] !== 'migrations');
            if ($tables !== []) {
                $names = array_map(fn (array $table): string => $connection->getQueryGrammar()->wrapTable($table['schema_qualified_name']), $tables);
                $connection->unprepared('TRUNCATE TABLE '.implode(', ', $names).' RESTART IDENTITY CASCADE');
            }

            return;
        }
        $schema=$connection->getSchemaBuilder(); $dispatcher=$connection->getEventDispatcher();
        $connection->unsetEventDispatcher();
        try {
            $schema->withoutForeignKeyConstraints(function () use ($connection,$schema): void {
                foreach ($schema->getTables($connection->getDatabaseName()) as $table) {
                    if ($table['name']==='migrations') { continue; }
                    if ($connection->table($table['name'])->exists()) { $connection->table($table['name'])->truncate(); }
                }
            });
        } finally { $connection->setEventDispatcher($dispatcher); }
    }
}