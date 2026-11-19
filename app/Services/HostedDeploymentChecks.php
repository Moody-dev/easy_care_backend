<?php

namespace App\Services;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Throwable;

class HostedDeploymentChecks
{
    /** @return array<string, bool> */
    public function run(): array
    {
        $key = config('app.key');
        $key = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $database = config('database.connections.mysql');
        $url = config('app.url');
        $root = config('deployment.persistent_root');
        $rootPath = is_string($root) ? realpath($root) : false;
        $publicPath = realpath(public_path());

        return [
            'hosting_platform' => in_array(config('deployment.platform'), ['render', 'oracle'], true),
            'production_errors' => config('app.env') === 'production' && config('app.debug') === false,
            'https_application_url' => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false && parse_url($url, PHP_URL_SCHEME) === 'https',
            'persistent_application_key' => is_string($key) && Encrypter::supported($key, config('app.cipher')),
            'mysql_application_user' => config('database.default') === 'mysql' && is_array($database)
                && empty($database['url']) && ! empty($database['host']) && ! empty($database['database'])
                && ! empty($database['username']) && strtolower($database['username']) !== 'root' && ! empty($database['password']),
            'secure_database_sessions' => config('session.driver') === 'database' && config('session.secure') === true && config('session.http_only') === true,
            'database_cache_and_queues' => config('cache.default') === 'database' && config('queue.default') === 'database'
                && config('queue.connections.reminders.driver') === 'database' && config('queue.connections.reminders.after_commit') === true,
            'persistent_private_files' => $rootPath !== false && $publicPath !== false && is_writable($rootPath)
                && ! $this->within($rootPath, $publicPath)
                && config('filesystems.default') === 'local'
                && config('filesystems.disks.protected.driver') === 'local' && config('filesystems.disks.protected.serve') === false
                && config('filesystems.disks.protected.visibility') === 'private'
                && $this->storedWithin(config('filesystems.disks.protected.root'), $rootPath)
                && $this->storedWithin(config('filesystems.disks.local.root'), $rootPath),
        ];
    }

    public function databaseReady(): bool
    {
        try {
            DB::connection()->select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function storedWithin(mixed $path, string $root): bool
    {
        return is_string($path) && ($resolved = realpath($path)) !== false && is_dir($resolved)
            && is_writable($resolved) && $this->within($resolved, $root);
    }

    private function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
