<?php

namespace App\Console\Commands;

use App\Services\LocalServiceChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hospital:check-local-services {--probe : Read local database connections and check private-directory availability}')]
#[Description('Check the supported single-server local-service configuration without exposing credentials')]
class CheckLocalServices extends Command
{
    public function handle(LocalServiceChecks $checks): int
    {
        $results=$checks->run((bool)$this->option('probe'));
        foreach ($results as $name=>$passed) { $this->line(($passed?'PASS ':'FAIL ').$name); }
        $this->line('This checks local service configuration only; WAN disconnection, browser assets, LAN access and production readiness require separate verification.');
        if (in_array(false,$results,true)) { $this->error('Local-service checks need attention. No settings or records were changed.'); return self::FAILURE; }
        $this->info('Local-service checks passed.');
        return self::SUCCESS;
    }
}