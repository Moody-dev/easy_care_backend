<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class SupabaseSetup
{
    private ?string $connectionHint = null;

    public function connectionHint(): ?string
    {
        return $this->connectionHint;
    }

    /** @return array<string, bool> */
    public function run(bool $probe = false): array
    {
        $settings = config('database.connections.pgsql');
        $host = $settings['host'] ?? null;
        $project = config('deployment.supabase_url');
        $projectHost = is_string($project) ? parse_url($project, PHP_URL_HOST) : null;
        $direct = is_string($projectHost) ? 'db.'.$projectHost : null;
        $pool = is_string($host) && str_ends_with($host, '.pooler.supabase.com');
        $user = $settings['username'] ?? null;
        $projectRef = is_string($projectHost) ? strstr($projectHost, '.', true) : null;
        $password = $settings['password'] ?? null;
        $checks = [
            'postgresql_testing_connection' => config('database.default') === 'pgsql' && empty($settings['url']),
            'supabase_project_url' => is_string($project) && preg_match('~^https://[a-z0-9]+\.supabase\.co/?$~D', $project) === 1,
            'supabase_database_endpoint' => ($host === $direct || $pool) && (int) ($settings['port'] ?? 0) === 5432,
            'database_user_and_password' => is_string($user) && $user !== '' && ($host === $direct || str_ends_with($user, '.'.$projectRef))
                && is_string($password) && $password !== '' && ! str_contains($password, 'YOUR-DATABASE-PASSWORD'),
            'verified_database_tls' => ($settings['sslmode'] ?? null) === 'verify-full'
                && is_string($settings['sslrootcert'] ?? null) && is_readable($settings['sslrootcert']),
            'private_application_schema' => ($settings['search_path'] ?? null) === ['easycare', 'extensions'],
            'utc_database_clock' => ($settings['timezone'] ?? null) === 'UTC',
        ];
        try {
            app(PostgresSchema::class)->assertCurrent();
            $checks['reviewed_schema_baseline'] = true;
        } catch (Throwable) {
            $checks['reviewed_schema_baseline'] = false;
        }
        if ($probe) {
            $ready = ! in_array(false, $checks, true);
            $checks['database_connection'] = false;
            $checks['private_schema_ready'] = false;
            if ($ready) {
                try {
                    $row = DB::selectOne('SELECT current_schema() AS schema, version() AS version');
                    $checks['database_connection'] = str_starts_with($row->version, 'PostgreSQL ');
                    $checks['private_schema_ready'] = $row->schema === 'easycare';
                } catch (Throwable $exception) {
                    $message = strtolower($exception->getMessage());
                    $this->connectionHint = match (true) {
                        str_contains($message, 'translate host') || str_contains($message, 'resolve') => 'Database hostname could not be resolved. Confirm the project is active and copy its current Connect settings.',
                        str_contains($message, 'password authentication') || str_contains($message, 'tenant or user') => 'Database authentication failed. Confirm the private database password and connection username.',
                        str_contains($message, 'certificate') || str_contains($message, 'ssl error') => 'TLS verification failed. Configure the trusted server CA from the project database settings.',
                        str_contains($message, 'network') || str_contains($message, 'route') || str_contains($message, 'timeout') => 'The database endpoint is unreachable. An IPv4-only backend needs the session pooler connection from the Connect panel.',
                        default => 'The database connection failed. Confirm the project status, endpoint, credentials and verified TLS configuration.',
                    };
                }
            }
        }

        return $checks;
    }
}
