<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class OracleSetupScriptTest extends TestCase
{
    private string $root;

    private string $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $shell = getenv('EASYCARE_TEST_SHELL') ?: (DIRECTORY_SEPARATOR === '/' ? '/bin/sh' : null);
        if (! is_string($shell) || ! is_file($shell)) {
            $this->markTestSkipped('A POSIX shell and OpenSSL are required for deployment-script checks.');
        }
        $this->shell = $shell;
        $this->root = storage_path('framework/testing/oracle-configure-'.bin2hex(random_bytes(8)));
        mkdir($this->root.'/bootstrap', recursive: true);
        copy(base_path('bootstrap/oracle-configure.sh'), $this->root.'/bootstrap/oracle-configure.sh');
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            if (is_file($this->root.'/.env.oracle')) {
                unlink($this->root.'/.env.oracle');
            }
            unlink($this->root.'/bootstrap/oracle-configure.sh');
            rmdir($this->root.'/bootstrap');
            rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_private_configuration_has_independent_random_secrets_without_console_disclosure(): void
    {
        $process = $this->configure('api.example.test');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $config = parse_ini_file($this->root.'/.env.oracle', scanner_mode: INI_SCANNER_RAW);
        $this->assertSame('api.example.test', $config['API_DOMAIN']);
        $this->assertSame(32, strlen(base64_decode(substr($config['APP_KEY'], 7), true)));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $config['MYSQL_PASSWORD']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $config['MYSQL_ROOT_PASSWORD']);
        $this->assertNotSame($config['MYSQL_PASSWORD'], $config['MYSQL_ROOT_PASSWORD']);
        foreach (['APP_KEY', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'] as $name) {
            $this->assertStringNotContainsString($config[$name], $process->getOutput().$process->getErrorOutput());
        }
        if (DIRECTORY_SEPARATOR === '/') {
            $this->assertSame(0600, fileperms($this->root.'/.env.oracle') & 0777);
        }
    }

    public function test_rerunning_cannot_rotate_a_saved_key_or_database_passwords(): void
    {
        $this->assertTrue($this->configure('api.example.test')->isSuccessful());
        $before = file_get_contents($this->root.'/.env.oracle');
        $this->assertFalse($this->configure('another.example.test')->isSuccessful());
        $this->assertSame($before, file_get_contents($this->root.'/.env.oracle'));
    }

    public function test_non_hostname_input_cannot_create_configuration_or_run_commands(): void
    {
        foreach (['', 'https://api.example.test', 'api.example.test;echo injected', '../other'] as $domain) {
            $this->assertFalse($this->configure($domain)->isSuccessful());
            $this->assertFileDoesNotExist($this->root.'/.env.oracle');
        }
    }

    private function configure(string $domain): Process
    {
        $process = new Process([$this->shell, str_replace('\\', '/', $this->root.'/bootstrap/oracle-configure.sh'), $domain], $this->root);
        $process->setTimeout(15)->run();

        return $process;
    }
}
