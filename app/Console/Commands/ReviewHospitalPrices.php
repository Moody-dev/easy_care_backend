<?php

namespace App\Console\Commands;

use App\Models\HospitalPriceImport;
use App\Support\FixedPoint;
use Illuminate\Console\Command;

class ReviewHospitalPrices extends Command
{
    protected $signature = 'prices:review {import : Retained import ID} {--kind= : Source kind} {--status= : Row status} {--page=1 : Review page, 100 rows at a time}';

    protected $description = 'Review retained source prices privately on the hospital server';

    public function handle(): int
    {
        $batch = HospitalPriceImport::query()->find($this->argument('import'));
        if ($batch === null || ! ctype_digit((string) $this->option('page')) || (int) $this->option('page') < 1) {
            $this->error('Choose an existing import and a positive page number.');

            return self::FAILURE;
        }
        $query = $batch->prices()->orderBy('id');
        foreach (['kind' => 'source_kind', 'status' => 'status'] as $option => $column) {
            if ($this->option($option) !== null) {
                $query->where($column, $this->option($option));
            }
        }
        $page = $query->paginate(100, ['*'], 'page', (int) $this->option('page'));
        $this->table(['File', 'Line', 'Group', 'Name', 'NGN', 'Status', 'Issues'], $page->map(fn ($row): array => array_map(
            fn (mixed $value): string => preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value),
            [$row->source_file, $row->source_line, $row->group_name, $row->label, $row->amount_micros === null ? '' : FixedPoint::format($row->amount_micros), $row->status, implode(', ', $row->issues)],
        ))->all());
        $this->line('Page '.$page->currentPage().' of '.$page->lastPage().'; '.$page->total().' matching source rows.');

        return self::SUCCESS;
    }
}
