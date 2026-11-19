<?php

namespace Tests\Feature;

use App\Services\RenderDeploymentChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RenderDeploymentChecksTest extends TestCase
{
    private string $root;

    protected string $checkCommand = 'deploy:check-render';

    protected string $checkService = RenderDeploymentChecks::class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/render-'.bin2hex(random_bytes(8)));
        mkdir($this->root.'/protected', recursive: true);
        mkdir($this->root.'/private');
        config(['deployment.render' => true, 'deployment.platform' => 'render', 'deployment.persistent_root' => $this->root,
            'app.env' => 'production', 'app.debug' => false, 'app.url' => 'https://development-api.example',
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'app.cipher' => 'AES-256-CBC',
            'database.default' => 'mysql', 'database.connections.mysql' => ['driver' => 'mysql', 'host' => 'private-database-sentinel',
                'database' => 'development', 'username' => 'application', 'password' => 'private-password-sentinel'],
            'session.driver' => 'database', 'session.secure' => true, 'session.http_only' => true,
            'cache.default' => 'database', 'queue.default' => 'database', 'queue.connections.reminders.driver' => 'database',
            'queue.connections.reminders.after_commit' => true, 'filesystems.default' => 'local',
            'filesystems.disks.protected.driver' => 'local', 'filesystems.disks.protected.visibility' => 'private',
            'filesystems.disks.protected.serve' => false, 'filesystems.disks.protected.root' => $this->root.'/protected',
            'filesystems.disks.local.root' => $this->root.'/private']);
    }

    protected function tearDown(): void
    {
        rmdir($this->root.'/protected');
        rmdir($this->root.'/private');
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_configuration_is_read_only_and_the_probe_is_explicit(): void
    {
        DB::shouldReceive('connection')->never();
        $this->assertNotContains(false, app($this->checkService)->run());
    }

    #[DataProvider('invalidSettings')]
    public function test_bad_configuration_prevents_database_access_without_disclosing_secrets(array $settings): void
    {
        config($settings);
        DB::shouldReceive('connection')->never();
        $this->assertSame(1, Artisan::call($this->checkCommand));
        $this->assertStringNotContainsString('sentinel', Artisan::output());
    }

    public static function invalidSettings(): array
    {
        return [
            [['deployment.render' => false]], [['app.debug' => true]], [['app.env' => 'local']],
            [['app.url' => 'http://development.example']], [['app.key' => 'invalid-key-sentinel']],
            [['database.connections.mysql.username' => 'root']], [['database.connections.mysql.password' => '']],
            [['database.connections.mysql.url' => 'mysql://private-database-sentinel']],
            [['session.secure' => false]], [['session.http_only' => false]], [['session.driver' => 'cookie']],
            [['queue.connections.reminders.after_commit' => false]], [['cache.default' => 'array']],
            [['filesystems.disks.protected.root' => '/unmounted-private-sentinel']], [['filesystems.disks.protected.serve' => true]],
            [['filesystems.disks.local.root' => dirname(__DIR__, 2).'/public']], [['deployment.persistent_root' => dirname(__DIR__, 2).'/public']],
        ];
    }

    public function test_database_failure_has_no_connection_details_in_console_output(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new RuntimeException('private-password-sentinel'));
        $this->assertSame(1, Artisan::call($this->checkCommand));
        $this->assertStringContainsString('FAIL private_database_ready', Artisan::output());
        $this->assertStringNotContainsString('sentinel', Artisan::output());
    }

    public function test_database_readiness_uses_a_read_only_probe(): void
    {
        DB::shouldReceive('connection->select')->once()->with('SELECT 1')->andReturn([]);
        $this->assertSame(0, Artisan::call($this->checkCommand));
    }

    public function test_wait_must_be_bounded(): void
    {
        DB::shouldReceive('connection')->never();
        $this->assertSame(1, Artisan::call($this->checkCommand, ['--wait' => '-1']));
        $this->assertSame(1, Artisan::call($this->checkCommand, ['--wait' => '301']));
    }
}
