<?php

namespace App\Console\Commands;

use App\Services\HostedDeploymentChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('deploy:check {--wait=0 : Seconds to wait for the private MySQL service, up to 300}')]
#[Description('Check the hosted runtime and private database without revealing connection secrets')]
class CheckHostedDeployment extends Command
{
    protected function checks(): HostedDeploymentChecks
    {
        return app(HostedDeploymentChecks::class);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $checks = $this->checks();
        $wait = (string) $this->option('wait');
        if (! ctype_digit($wait) || (int) $wait > 300) {
            $this->error('Use a wait time between zero and 300 seconds.');

            return self::FAILURE;
        }
        $results = $checks->run();
        foreach ($results as $name => $passed) {
            $this->line(($passed ? 'PASS ' : 'FAIL ').$name);
        }
        if (in_array(false, $results, true)) {
            return self::FAILURE;
        }
        $deadline = microtime(true) + (int) $wait;
        do {
            if ($checks->databaseReady()) {
                $this->info('PASS private_database_ready');

                return self::SUCCESS;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            sleep(1);
        } while (true);
        $this->error('FAIL private_database_ready');

        return self::FAILURE;
    }
}
