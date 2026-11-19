<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\HospitalPriceImport;
use App\Models\HospitalReferencePrice;
use App\Models\Ward;
use App\Services\AuditTrail;
use App\Services\HospitalPriceFiles;
use App\Services\HospitalPriceImporter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class HospitalPriceImportTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->directory = storage_path('framework/testing/price-import-'.bin2hex(random_bytes(8)));
        mkdir($this->directory, recursive: true);
        foreach (HospitalPriceFiles::FILES as $kind => $filename) {
            $contents = match ($kind) {
                'accommodation' => "Accommodation,Bed Name,Amount,,,\nNorth Ward,Bed 1,12500,,,\nSouth Ward,Bed 1,15000\n",
                'medication' => "Medication,,,\n,Example capsule,\"14,917.5\",,,\n,Example capsule,14918\n,\"Example\nliquid\",2000\n",
                'procedure' => "General,,,\n,Same Procedure,100\n,Conflicted,200\nAdditional,,,\n,Same Procedure,100.00\n,Conflicted,300\n,,350000\n",
                'investigation', 'imaging', 'xray' => "Section,,,\n,Example {$kind},\"12,500\",,,\n",
                'registration' => "Registration,,\nStaff,0\n",
                default => "Section,,\nExample {$kind},2500\n",
            };
            file_put_contents($this->directory.DIRECTORY_SEPARATOR.$filename, $contents);
        }
    }

    protected function tearDown(): void
    {
        foreach (HospitalPriceFiles::FILES as $filename) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$filename;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_confirmed_single_item_units_are_retained_once_without_rewriting_sources_or_publishing_drugs(): void
    {
        $batch = app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
        $before = $batch->prices()->get()->toArray();
        $this->artisan('prices:confirm-medication-units', ['import' => $batch->id])->assertFailed();
        $this->artisan('prices:confirm-medication-units', ['import' => $batch->id, '--per-item' => true])->assertSuccessful();
        $this->artisan('prices:confirm-medication-units', ['import' => $batch->id, '--per-item' => true])->assertSuccessful();
        $this->assertDatabaseCount('hospital_medication_units', 1);
        $this->assertSame($before, $batch->prices()->get()->toArray());
        $this->assertDatabaseCount('drugs', 0);
        $this->artisan('prices:review', ['import' => $batch->id, '--kind' => 'medication'])
            ->expectsOutputToContain('NGN per one sellable item')->assertSuccessful();
        try {
            DB::table('hospital_medication_units')->update(['basis' => 'pack']);
            $this->fail('Unit confirmations cannot be replaced.');
        } catch (QueryException) {
            $this->assertDatabaseHas('hospital_medication_units', ['basis' => 'per_sellable_item']);
        }
    }

    public function test_exact_prices_duplicates_conflicts_and_units_are_distinguished(): void
    {
        $parsed = app(HospitalPriceFiles::class)->read($this->directory);
        $rows = collect($parsed['rows']);
        $this->assertCount(16, $rows);
        $this->assertSame(0, $rows->firstWhere('label', 'Staff')['amount_micros']);
        $medications = $rows->where('source_kind', 'medication')->values();
        $this->assertSame(14917500000, $medications[0]['amount_micros']);
        $this->assertSame(['conflicting_prices'], $medications[0]['issues']);
        $this->assertSame(['pending_units'], $medications->pluck('status')->unique()->values()->all());
        $this->assertSame('Example liquid', $medications[2]['label']);
        $this->assertSame("Example\nliquid", $medications[2]['raw_cells'][1]);
        $this->assertSame(4, $medications[2]['source_line']);
        $this->assertCount(2, $rows->where('status', 'conflict'));
        $this->assertSame(2, $rows->firstWhere('status', 'duplicate')['duplicate_line']);
        $this->assertSame(350000000000, $rows->firstWhere('status', 'invalid')['amount_micros']);
    }

    public function test_dry_run_and_missing_bed_confirmation_do_not_write(): void
    {
        $this->artisan('prices:import', ['directory' => $this->directory])->assertSuccessful();
        $this->artisan('prices:import', ['directory' => $this->directory, '--apply' => true])->assertFailed();
        $this->assertDatabaseCount('hospital_price_imports', 0);
        $this->assertDatabaseCount('beds', 0);
    }

    public function test_apply_activates_only_beds_and_replay_is_stable(): void
    {
        $parsed = app(HospitalPriceFiles::class)->read($this->directory);
        $batch = app(HospitalPriceImporter::class)->apply($parsed, true);
        $auditCount = DB::table('audit_events')->count();
        $this->travel(1)->days();
        $retry = app(HospitalPriceImporter::class)->apply($parsed, true);
        $this->assertSame($batch->id, $retry->id);
        $this->assertTrue($batch->effective_at->equalTo($retry->effective_at));
        $this->assertDatabaseCount('audit_events', $auditCount);
        $this->assertDatabaseCount('wards', 2);
        $this->assertDatabaseCount('beds', 2);
        $this->assertDatabaseCount('hospital_reference_prices', 16);
        $this->assertSame(['12500.00', '15000.00'], Bed::query()->orderBy('id')->pluck('daily_rate')->all());
        $this->assertCount(2, $batch->prices()->where('status', 'active_bed_rate')->whereNotNull('bed_id')->get());
        foreach (['users', 'services', 'service_prices', 'drugs', 'stock_movements', 'admissions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(1, Bed::query()->first()->version);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'prices.imported')->whereNull('actor_id')->where('channel', 'console')->count());
    }

    public function test_original_bytes_are_encrypted_and_recoverable(): void
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'Medical Imaging (Scan).csv';
        $bytes = "Section\n,Scan \x96 example,2500\n";
        file_put_contents($path, $bytes);
        $batch = app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
        $source = $batch->fresh()->sources['Medical Imaging (Scan).csv'];
        $this->assertSame('Windows-1252', $source['encoding']);
        $this->assertSame($bytes, base64_decode($source['base64'], true));
        $this->assertSame(hash('sha256', $bytes), $source['sha256']);
        $this->assertStringNotContainsString('base64', DB::table('hospital_price_imports')->value('sources'));
        $this->assertArrayNotHasKey('sources', $batch->toArray());
    }

    public function test_malformed_numbers_are_held_without_rounding_or_formula_execution(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'Feeding Upload.csv', "Meals\nBad grouping,\"12,50\"\nFormula,=1+1\nToo precise,1.1234567\nExact,1.123456\n=UNTRUSTED(),20\n");
        $rows = collect(app(HospitalPriceFiles::class)->read($this->directory)['rows'])->where('source_kind', 'feeding')->values();
        $this->assertSame(['invalid', 'invalid', 'invalid', 'ready', 'ready'], $rows->pluck('status')->all());
        $this->assertSame(1123456, $rows[3]['amount_micros']);
        $this->assertSame('=UNTRUSTED()', $rows[4]['label']);
    }

    public function test_existing_ward_collision_rolls_back_whole_import(): void
    {
        Ward::factory()->create(['name' => 'South Ward']);
        try {
            app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
            $this->fail('An unconfirmed ward identity must not be matched automatically.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('hospital_price_imports', 0);
            $this->assertDatabaseCount('hospital_reference_prices', 0);
            $this->assertDatabaseCount('wards', 1);
            $this->assertDatabaseCount('beds', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_failed_audit_rolls_back_prices_locations_and_source_bytes(): void
    {
        $this->mock(AuditTrail::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        try {
            app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
            $this->fail('Audit failure must roll back the import.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
            foreach (['hospital_price_imports', 'hospital_reference_prices', 'wards', 'beds'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        }
    }

    public function test_changed_sources_cannot_overwrite_existing_bed_rates(): void
    {
        app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'Accommodation - (Upload).csv', "Accommodation,Bed Name,Amount\nNorth Ward,Bed 1,19000\n");
        try {
            app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
            $this->fail('Existing rates require explicit versioned changes.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('hospital_price_imports', 1);
            $this->assertSame('12500.00', Bed::query()->orderBy('id')->first()->daily_rate);
        }
    }

    public function test_missing_file_fails_before_any_changes(): void
    {
        unlink($this->directory.DIRECTORY_SEPARATOR.'Procedure.csv');
        $this->artisan('prices:import', ['directory' => $this->directory, '--apply' => true, '--confirm-daily-beds' => true])->assertFailed();
        $this->assertDatabaseCount('hospital_price_imports', 0);
    }

    public function test_retained_sources_cannot_be_updated_or_deleted(): void
    {
        $row = HospitalReferencePrice::factory()->create();
        foreach ([['hospital_reference_prices', 'update'], ['hospital_reference_prices', 'delete'], ['hospital_price_imports', 'update'], ['hospital_price_imports', 'delete']] as [$table, $operation]) {
            try {
                $operation === 'delete' ? DB::table($table)->delete() : DB::table($table)->update($table === 'hospital_reference_prices' ? ['status' => 'ready'] : ['content_hash' => str_repeat('a', 64)]);
                $this->fail('Retained sources must be immutable.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Price source records are retained', $exception->getMessage());
            }
        }
        $this->assertNotNull($row->fresh());
    }

    public function test_rollback_refuses_retained_sources_and_review_is_paginated(): void
    {
        $batch = app(HospitalPriceImporter::class)->apply(app(HospitalPriceFiles::class)->read($this->directory), true);
        $this->artisan('prices:review', ['import' => $batch->id, '--status' => 'conflict'])->expectsOutputToContain('2 matching source rows')->assertSuccessful();
        $this->artisan('prices:review', ['import' => $batch->id, '--page' => '-1'])->assertFailed();
        $migration = require database_path('migrations/2026_11_19_034432_create_hospital_price_imports_table.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
