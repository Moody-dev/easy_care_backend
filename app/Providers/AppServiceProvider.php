<?php

namespace App\Providers;

use App\Models\Department;
use App\Models\Patient;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use App\Policies\PatientPolicy;
use App\Policies\UserPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        \Illuminate\Database\Connection::resolverFor('pgsql', fn ($connection, $database, $prefix, $config) =>
            new \App\Services\PrivatePostgresConnection($connection, $database, $prefix, $config));
        $this->app->extend('migrator', fn ($migrator, $app) => new \App\Services\EngineMigrator(
            $app['migration.repository'], $app['db'], $app['files'], $app['events'],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (array_keys(config('authorization.permissions')) as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
        Gate::define('reports.discover',fn (User $user): bool => $user->hasPermission('reports.activity')
            || $user->hasPermission('reports.inventory') || $user->hasPermission('reports.billing') || $user->hasPermission('reports.hmo'));

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(Patient::class, PatientPolicy::class);

        RateLimiter::for('stock-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('stock-writes:'.$request->user('web')?->id.'|'.$request->ip()));
        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('exports:'.$request->user('web')?->id.'|'.$request->ip()));
        RateLimiter::for('reports', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('reports:'.$request->user('web')?->id.'|'.$request->ip()));
        RateLimiter::for('reminder-writes', fn (Request $request): Limit => Limit::perMinute(60)
            ->by('reminder-writes:'.$request->user('web')?->id.'|'.$request->ip()));
        RateLimiter::for('hmo-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('hmo-writes:'.$request->user('web')?->id.'|'.$request->ip()));
        RateLimiter::for('billing-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('billing-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('clinical-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('clinical-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('admission-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('admission-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('patient-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('patient-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('encounter-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('encounter-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('appointment-writes', fn (Request $request): Limit => Limit::perMinute(30)
            ->by('appointment-writes:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user('web')?->is_active
                ? 'staff:'.$request->user('web')->getAuthIdentifier()
                : 'ip:'.$request->ip()));

        RateLimiter::for('staff-login', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-login-ip:'.$request->ip()));

        RateLimiter::for('protected-files-upload', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('protected-files-upload:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('staff-management', fn (Request $request): Limit => Limit::perMinute(20)
            ->by('staff-management:'.$request->user('web')?->id.'|'.$request->ip()));

        RateLimiter::for('staff-recovery', fn (Request $request): array => [
            Limit::perMinute(10)->by('staff-recovery-ip:'.$request->ip()),
            Limit::perMinute(5)->by('staff-recovery-user:'.hash('sha256',
                (is_string($request->input('username')) ? mb_strtolower($request->input('username')) : '').'|'.$request->ip())),
        ]);
    }
}
