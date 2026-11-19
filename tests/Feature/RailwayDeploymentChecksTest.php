<?php

namespace Tests\Feature;

use App\Services\HostedDeploymentChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class RailwayDeploymentChecksTest extends HostedDeploymentChecksTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['deployment.platform' => 'railway']);
    }

    public function test_supabase_requires_verified_tls_before_any_hosted_database_probe(): void
    {
        config(['database.default' => 'pgsql', 'deployment.supabase_url' => 'https://testingproject.supabase.co',
            'database.connections.pgsql' => ['driver' => 'pgsql', 'host' => 'aws-0-example.pooler.supabase.com', 'port' => 5432,
                'database' => 'postgres', 'username' => 'postgres.testingproject', 'password' => 'private-password-sentinel',
                'sslmode' => 'verify-full', 'sslrootcert' => base_path('bootstrap/certs/supabase-root-2021.crt'),
                'search_path' => ['easycare', 'extensions'], 'timezone' => 'UTC']]);
        DB::shouldReceive('connection')->never();
        $this->assertNotContains(false, app(HostedDeploymentChecks::class)->run());
        $this->assertArrayNotHasKey('mysql_application_user', app(HostedDeploymentChecks::class)->run());

        config(['database.connections.pgsql.sslmode' => 'require']);
        $this->assertSame(1, Artisan::call('deploy:check'));
        $this->assertStringContainsString('FAIL supabase_database_connection', Artisan::output());
        $this->assertStringNotContainsString('sentinel', Artisan::output());
    }
}
