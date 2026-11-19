<?php

namespace App\Console\Commands;

use App\Services\HospitalPriceFiles;
use App\Services\HospitalPriceImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ImportHospitalPrices extends Command
{
    protected $signature = 'prices:import {directory : Directory containing the nine hospital CSV files} {--apply : Save the sources and confirmed bed rates} {--confirm-daily-beds : Accommodation amounts are NGN daily bed rates, effective now}';

    protected $description = 'Validate and retain hospital prices; medications and service publication stay pending';

    public function handle(HospitalPriceFiles $files, HospitalPriceImporter $importer): int
    {
        try {
            $parsed = $files->read($this->argument('directory'));
            $rows = collect($parsed['rows']);
            if ($this->option('apply')) {
                $batch = $importer->apply($parsed, (bool) $this->option('confirm-daily-beds'));
                $this->info('Retained import '.$batch->id.'; effective '.$batch->effective_at->toIso8601String());
                $rows = $batch->prices()->get();
            } else {
                $this->info('Validation only. No database changes.');
            }
            $this->table(['Status', 'Rows'], $rows->countBy('status')->map(fn (int $count, string $status): array => [$status, $count])->values()->all());
            $this->line('Medication units remain unconfirmed. Ready service references are not published billable prices.');

            return self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
