<?php

namespace App\Console\Commands;

use App\Services\InvestigationCatalog;
use Illuminate\Console\Command;
use Throwable;

class PublishInvestigation extends Command
{
    protected $signature = 'investigations:publish {definition : Local approved JSON definition} {expectedVersion : Zero for a new test, otherwise the saved catalog version}
        {--publisher= : Authorized publishing staff ID} {--approval-reference= : Hospital approval reference} {--reason= : Publication or retirement reason}';

    protected $description = 'Publish an approved investigation catalog definition as a retained immutable version';

    public function handle(InvestigationCatalog $catalog): int
    {
        try {
            $path = $this->argument('definition');
            $expected = (string) $this->argument('expectedVersion');
            $publisher = (string) $this->option('publisher');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 262144
                || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $expected) !== 1
                || preg_match('/^[1-9][0-9]{0,17}$/D', $publisher) !== 1) {
                $this->error('Choose a readable local JSON file up to 256 KiB, a saved publisher and an expected version.');
                return self::FAILURE;
            }
            $schema = $catalog->publish(json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR), (int) $publisher,
                (int) $expected, (string) $this->option('approval-reference'), (string) $this->option('reason'));
            $this->info('Confirmed investigation #'.$schema->test_id.', version '.$schema->version.', schema #'.$schema->id.'.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Publication was not confirmed. Check the approved definition, publisher, approval reference and expected version; retry unchanged after an unknown outcome.');

            return self::FAILURE;
        }
    }
}
