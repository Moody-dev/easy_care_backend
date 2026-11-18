<?php

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['csv_content']);
        $middleware->prependToGroup('api', \App\Http\Middleware\AuditSecurityEvents::class);
        $middleware->appendToGroup('api', 'throttle:api');
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api', 'api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): ?bool {
            if (request()->is('api/v1/exports','api/v1/exports/*')) { logger()->error('A document export database operation failed.'); return false; }
            if (request()->is('api/v1/dashboard','api/v1/reports','api/v1/reports/*')) {
                logger()->error('A reporting database operation failed.');
                return false;
            }
            if (request()->is('api/v1/reminders','api/v1/reminders/*','api/v1/notifications','api/v1/notifications/*')) {
                logger()->error('A staff reminder database operation failed.');
                return false;
            }
            if (request()->is('api/v1/hmo','api/v1/hmo/*')) { logger()->error('An HMO database operation failed.'); return false; }
            if (request()->is('api/v1/billing', 'api/v1/billing/*')) {
                logger()->error('A billing database operation failed.');

                return false;
            }
            if (request()->is('api/v1/medications', 'api/v1/medications/*')) {
                logger()->error('A medication workflow database operation failed.');

                return false;
            }
            if (request()->is('api/v1/investigations/results*', 'api/v1/investigations/items/*', 'api/v1/investigations/orders*', 'api/v1/investigations/orders/*', 'api/v1/investigations/queue', 'api/v1/encounters/*/investigations')) {
                logger()->error('An investigation request database operation failed.');

                return false;
            }
            if (request()->is('api/v1/investigations/catalog', 'api/v1/investigations/catalog/*')) {
                logger()->error('An investigation catalog database operation failed.');

                return false;
            }
            if (request()->is('api/v1/care-programs', 'api/v1/care-programs/*', 'api/v1/encounters/*/care-programs')) {
                logger()->error('A care-program database operation failed.');
                return false;
            }
            if (request()->is('api/v1/admissions', 'api/v1/admissions/*', 'api/v1/locations/*', 'api/v1/encounters/*/admission')) {
                logger()->error('An admission or location database operation failed.');

                return false;
            }
            if (request()->is('api/v1/prescriptions/*', 'api/v1/clinical/*', 'api/v1/patients', 'api/v1/patients/*', 'api/v1/appointments', 'api/v1/appointments/*', 'api/v1/encounters', 'api/v1/encounters/*', 'api/v1/queues')) {
                logger()->error(request()->is('api/v1/prescriptions/*', 'api/v1/clinical/*', 'api/v1/encounters/*/clinical*', 'api/v1/encounters/*/prescriptions*') ? 'A clinical database operation failed.' : (request()->is('api/v1/encounters', 'api/v1/encounters/*', 'api/v1/queues') ? 'An encounter database operation failed.' : (request()->is('api/v1/appointments', 'api/v1/appointments/*')
                    ? 'An appointment database operation failed.' : 'A patient database operation failed.')));

                return false;
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api', 'api/*') || $request->expectsJson(),
        );
        $exceptions->respond(new ApiErrorResponse);
    })->create();
