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
        if (DB::getDriverName()!=='mysql') { $this->rebuildTestDatabase(); return; }
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
        if ($connection->getDriverName()!=='mysql') { throw new \RuntimeException('Committed fixture cleanup requires isolated MySQL.'); }
        if ($connection->transactionLevel()!==0) { throw new \RuntimeException('Finish the test transaction before clearing committed fixtures.'); }
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