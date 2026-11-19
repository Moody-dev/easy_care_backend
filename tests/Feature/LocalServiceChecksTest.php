<?php

namespace Tests\Feature;

use App\Services\LocalServiceChecks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalServiceChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'mysql','database.connections.mysql.driver'=>'mysql','database.connections.mysql.host'=>'127.0.0.1',
            'database.connections.mysql.database'=>'easycare_test','database.connections.mysql.username'=>'easycare_test',
            'database.connections.mysql.url'=>null,'database.connections.mysql.read'=>[],'database.connections.mysql.write'=>[],
            'session.driver'=>'database','session.connection'=>null,'cache.default'=>'database','cache.stores.database.driver'=>'database',
            'cache.stores.database.connection'=>null,'cache.stores.database.lock_connection'=>null,
            'queue.default'=>'database','queue.connections.database.driver'=>'database','queue.connections.database.connection'=>null,
            'queue.connections.reminders.driver'=>'database','queue.connections.reminders.connection'=>null,'queue.connections.reminders.after_commit'=>true,
            'queue.failed.driver'=>'database-uuids','queue.failed.database'=>'mysql','queue.batching.database'=>'mysql',
            'filesystems.default'=>'local','filesystems.disks.local.driver'=>'local',
            'filesystems.disks.protected.driver'=>'local','filesystems.disks.protected.root'=>storage_path('framework'),
            'filesystems.disks.protected.serve'=>false,'filesystems.disks.protected.visibility'=>'private',
            'mail.default'=>'log','mail.mailers.log.transport'=>'log',
            'logging.default'=>'single','logging.channels.single.driver'=>'single','logging.channels.single.path'=>storage_path('logs/laravel.log'),
            'app.maintenance.driver'=>'file']);
    }

    public function test_config_check_is_read_only_and_does_not_open_a_database_connection(): void
    {
        DB::shouldReceive('connection')->never();
        $this->assertNotContains(false,app(LocalServiceChecks::class)->run());
        $this->assertSame(0,Artisan::call('hospital:check-local-services'));
        $this->assertStringContainsString('WAN disconnection',Artisan::output());
        $this->assertStringNotContainsString(storage_path(),Artisan::output());
    }

    #[DataProvider('nonLocalSettings')]
    public function test_unsupported_service_settings_fail_without_probing_or_disclosing_the_endpoint(array $settings,string $check): void
    {
        config($settings); DB::shouldReceive('connection')->never();
        $result=app(LocalServiceChecks::class)->run(probe:true);
        $this->assertFalse($result[$check]); $this->assertFalse($result['local_service_probe']);
        $this->assertSame(1,Artisan::call('hospital:check-local-services',['--probe'=>true]));
        $this->assertStringNotContainsString('private-service-sentinel',Artisan::output());
    }

    public static function nonLocalSettings(): array
    {
        return [
            [['database.connections.mysql.host'=>'private-service-sentinel.example'],'database_is_local_mysql'],
            [['database.connections.mysql.url'=>'mysql://private-service-sentinel'],'database_is_local_mysql'],
            [['database.connections.mysql.read'=>['host'=>'private-service-sentinel.example']],'database_is_local_mysql'],
            [['database.connections.mysql.write'=>['host'=>'private-service-sentinel.example']],'database_is_local_mysql'],
            [['database.connections.mysql.read'=>[['host'=>'private-service-sentinel.example']]],'database_is_local_mysql'],
            [['session.driver'=>'redis'],'sessions_use_local_database'],
            [['session.connection'=>'unconfigured'],'sessions_use_local_database'],
            [['cache.default'=>'redis'],'cache_uses_local_database'],
            [['cache.stores.database.lock_connection'=>'unconfigured'],'cache_uses_local_database'],
            [['queue.default'=>'sqs'],'queue_uses_local_database'],
            [['queue.connections.reminders.driver'=>'redis'],'reminder_queue_uses_local_database'],
            [['queue.connections.reminders.after_commit'=>false],'reminder_queue_uses_local_database'],
            [['queue.failed.driver'=>'dynamodb'],'queue_history_uses_local_database'],
            [['queue.batching.database'=>'unconfigured'],'queue_history_uses_local_database'],
            [['filesystems.default'=>'s3'],'default_files_use_local_disk'],
            [['filesystems.disks.local.root'=>'//private-service-sentinel/share'],'default_files_use_local_disk'],
            [['filesystems.disks.protected.driver'=>'s3'],'protected_files_are_private_local'],
            [['filesystems.disks.protected.serve'=>true],'protected_files_are_private_local'],
            [['filesystems.disks.protected.visibility'=>'public'],'protected_files_are_private_local'],
            [['filesystems.disks.protected.root'=>'https://private-service-sentinel.example/files'],'protected_files_are_private_local'],
            [['filesystems.disks.protected.root'=>'//private-service-sentinel/share'],'protected_files_are_private_local'],
            [['mail.default'=>'smtp'],'mail_has_no_network_transport'],
            [['mail.mailers.log.channel'=>'slack'],'mail_has_no_network_transport'],
            [['logging.default'=>'papertrail'],'logs_use_local_channels'],
            [['logging.channels.single.path'=>'//private-service-sentinel/share'],'logs_use_local_channels'],
            [['logging.default'=>'stack','logging.channels.stack.channels'=>['stack']],'logs_use_local_channels'],
            [['app.maintenance.driver'=>'cache'],'maintenance_uses_local_file'],
        ];
    }

    public function test_private_root_must_exist_outside_the_public_directory(): void
    {
        config(['filesystems.disks.protected.root'=>public_path()]);
        $this->assertFalse(app(LocalServiceChecks::class)->run()['protected_files_are_private_local']);
    }

    public function test_probe_errors_have_a_failure_exit_and_do_not_expose_private_exception_details(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new \RuntimeException('private-service-sentinel'));
        $this->assertSame(1,Artisan::call('hospital:check-local-services',['--probe'=>true]));
        $this->assertStringContainsString('FAIL local_service_probe',Artisan::output());
        $this->assertStringNotContainsString('private-service-sentinel',Artisan::output());
    }
}
