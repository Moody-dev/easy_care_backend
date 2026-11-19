<?php

namespace Tests\Feature;

use App\Services\PostgresSchema;
use App\Services\SupabaseSetup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SupabaseSetupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql', 'deployment.supabase_url' => 'https://testingproject.supabase.co',
            'database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'db.testingproject.supabase.co', 'port' => 5432,
                'database' => 'postgres', 'username' => 'postgres', 'password' => 'private-test-password',
                'sslmode' => 'verify-full', 'sslrootcert' => __FILE__, 'search_path' => ['easycare', 'extensions'], 'timezone' => 'UTC']]);
    }

    public function test_connection_profiles_require_no_supabase_api_secret_or_engine_replacement(): void
    {
        $setup = app(SupabaseSetup::class);
        $this->assertNotContains(false, $setup->run());
        config(['database.connections.pgsql.host' => 'aws-0-example.pooler.supabase.com',
            'database.connections.pgsql.username' => 'postgres.testingproject']);
        $this->assertNotContains(false, $setup->run());
        $this->assertSame('mysql', config('database.connections.mysql.driver'));
        $this->assertSame(0, Artisan::call('supabase:check'));
        $this->assertStringNotContainsString('private-test-password', Artisan::output());
    }

    #[DataProvider('invalidProfiles')]
    public function test_incomplete_or_unverified_connections_fail_before_preparing_data(array $settings): void
    {
        config($settings);
        DB::shouldReceive('transaction')->never();
        $this->assertContains(false, app(SupabaseSetup::class)->run());
        $this->assertSame(1, Artisan::call('supabase:prepare'));
        $this->assertStringNotContainsString('private-test-password', Artisan::output());
    }

    public static function invalidProfiles(): array
    {
        return [
            [['database.default' => 'mysql']], [['deployment.supabase_url' => null]],
            [['database.connections.pgsql.url' => 'postgresql://unreviewed']],
            [['database.connections.pgsql.host' => 'unrelated.example']],
            [['database.connections.pgsql.port' => 6543]], [['database.connections.pgsql.password' => '']],
            [['database.connections.pgsql.password' => '[YOUR-DATABASE-PASSWORD]']],
            [['database.connections.pgsql.sslmode' => 'require']], [['database.connections.pgsql.sslmode' => 'disable']],
            [['database.connections.pgsql.sslrootcert' => null]], [['database.connections.pgsql.search_path' => ['public']]],
            [['database.connections.pgsql.timezone' => 'Africa/Lagos']],
            [['database.connections.pgsql.host' => 'aws-0-example.pooler.supabase.com', 'database.connections.pgsql.username' => 'postgres.otherproject']],
        ];
    }

    public function test_failed_database_probes_do_not_print_private_connection_diagnostics(): void
    {
        DB::shouldReceive('selectOne')->once()->andThrow(new RuntimeException('private-test-password sensitive-server-detail'));
        $this->assertSame(1, Artisan::call('supabase:check', ['--probe' => true]));
        $this->assertStringNotContainsString('private-test-password', Artisan::output());
        $this->assertStringNotContainsString('sensitive-server-detail', Artisan::output());
    }

    public function test_database_certificate_path_does_not_depend_on_the_web_server_working_directory(): void
    {
        $workingDirectory = getcwd();
        chdir(public_path());
        try {
            $settings = require config_path('database.php');
            $certificate = $settings['connections']['pgsql']['sslrootcert'];
            $this->assertTrue(is_readable($certificate));
            $this->assertNotSame('bootstrap/certs/supabase-root-2021.crt', $certificate);
        } finally {
            chdir($workingDirectory);
        }
    }

    public function test_stale_engine_baselines_are_rejected_before_migration(): void
    {
        $baseline = new PostgresSchema;
        $manifest = $baseline->manifest();
        $manifest['migrations'] = [];
        $this->partialMock(PostgresSchema::class, fn ($mock) => $mock->shouldReceive('manifest')->andReturn($manifest));
        $this->expectException(RuntimeException::class);
        app(PostgresSchema::class)->assertCurrent();
    }
}
