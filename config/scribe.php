<?php

use App\Support\Documentation\ApiHeaders;
use App\Support\Documentation\ApiMetadata;
use App\Support\Documentation\ApiParameters;
use App\Support\Documentation\ApiResponses;
use App\Support\Documentation\SessionSecurity;
use Knuckles\Scribe\Config\Defaults;
use Knuckles\Scribe\Extracting\Strategies;

if (! class_exists(Defaults::class)) {
    return [];
}

return [
    'title' => 'EasyCare API Reference',
    'description' => file_get_contents(resource_path('docs/api-guide.md')),
    'intro_text' => 'Examples are synthetic. Documentation generation does not call API endpoints or read application records. Download the Postman collection or OpenAPI specification using the links above.',
    'base_url' => env('SCRIBE_BASE_URL', 'https://easycare-development-api-production.up.railway.app'),
    'routes' => [['match' => ['prefixes' => ['api/v1/*'], 'domains' => ['*']], 'include' => [], 'exclude' => []]],
    'type' => 'static',
    'theme' => 'default',
    'static' => ['output_path' => 'public/docs'],
    'laravel' => ['add_routes' => false, 'docs_url' => '/docs', 'middleware' => [], 'assets_directory' => null],
    'try_it_out' => ['enabled' => false, 'base_url' => null, 'use_csrf' => true, 'csrf_url' => '/api/v1/auth/csrf-token'],
    'auth' => ['enabled' => false, 'default' => false, 'in' => 'header', 'name' => 'Cookie',
        'use_value' => null, 'placeholder' => null, 'extra_info' => 'Use the staff session and CSRF flow described in the integration guide.'],
    'example_languages' => ['bash', 'javascript'],
    'postman' => ['enabled' => true, 'overrides' => [
        'variable.1' => ['key' => 'csrf_token', 'value' => '', 'type' => 'string'],
        'variable.2' => ['key' => 'idempotency_key', 'value' => '', 'type' => 'string'],
        'event' => [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => [
            'try { const data = pm.response.json().data; if (data && data.csrf_token) { pm.collectionVariables.set("csrf_token", data.csrf_token); } } catch (error) {}',
        ]]]],
    ]],
    'openapi' => ['enabled' => true, 'version' => '3.0.3', 'overrides' => ['info.version' => 'v1'], 'generators' => [SessionSecurity::class]],
    'groups' => ['default' => 'API', 'order' => ['Health', 'Auth', 'Patients', 'Appointments', 'Encounters']],
    'logo' => false,
    'last_updated' => 'API version v1',
    'examples' => ['faker_seed' => 1234, 'models_source' => []],
    'strategies' => [
        'metadata' => [...Defaults::METADATA_STRATEGIES, ApiMetadata::class],
        'headers' => [ApiHeaders::class],
        'urlParameters' => Defaults::URL_PARAMETERS_STRATEGIES,
        'queryParameters' => [ApiParameters::wrapWithSettings(otherSettings: ['location' => 'query']),
            Strategies\QueryParameters\GetFromInlineValidator::class, Strategies\QueryParameters\GetFromQueryParamTag::class],
        'bodyParameters' => [ApiParameters::class, Strategies\BodyParameters\GetFromInlineValidator::class,
            Strategies\BodyParameters\GetFromBodyParamTag::class],
        'responses' => [Strategies\Responses\UseResponseTag::class, ApiResponses::class],
        'responseFields' => Defaults::RESPONSE_FIELDS_STRATEGIES,
    ],
    'database_connections_to_transact' => [],
    'fractal' => ['serializer' => null],
];
