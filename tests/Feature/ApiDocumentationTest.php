<?php

namespace Tests\Feature;

use App\Support\Documentation\ApiHeaders;
use App\Support\Documentation\ApiMetadata;
use App\Support\Documentation\ApiParameters;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    public function test_workflow_fields_and_permissions_are_extracted_without_executing_requests(): void
    {
        DB::shouldReceive('connection')->never();
        $config = new DocumentationConfig(config('scribe'));
        $routes = Route::getRoutes()->getRoutesByName();
        $patient = ExtractedEndpointData::fromRoute($routes['api.v1.patients.store']);
        $parameters = (new ApiParameters($config))($patient);
        $this->assertTrue($parameters['mrn']['sometimes']);
        $this->assertArrayHasKey('number_reservation_token', $parameters);
        $this->assertTrue($parameters['first_name']['required']);
        $this->assertArrayNotHasKey('page', $parameters);
        $metadata = (new ApiMetadata($config))($patient);
        $this->assertTrue($metadata['authenticated']);
        $this->assertStringContainsString('patients.create', $metadata['description']);
        $this->assertSame('{{idempotency_key}}', (new ApiHeaders($config))($patient)['Idempotency-Key']);

        $listing = ExtractedEndpointData::fromRoute($routes['api.v1.patients.index']);
        $this->assertArrayHasKey('per_page', (new ApiParameters($config))($listing, ['location' => 'query']));
        $this->assertSame([], (new ApiParameters($config))($listing));
        $login = ExtractedEndpointData::fromRoute($routes['api.v1.auth.login']);
        $this->assertFalse((new ApiMetadata($config))($login)['authenticated']);
        $this->assertArrayNotHasKey('Idempotency-Key', (new ApiHeaders($config))($login));
        $catalog = ExtractedEndpointData::fromRoute($routes['api.v1.stock.catalog.store']);
        $fields = (new ApiParameters($config))($catalog);
        $this->assertArrayHasKey('base_unit_id', $fields);
        $this->assertArrayHasKey('amount', $fields);
        $this->assertFalse($fields['base_unit_id']['required']);
        $this->assertStringContainsString('Required for: drugs', $fields['base_unit_id']['description']);
    }

    public function test_generated_reference_covers_every_route_without_database_or_network_calls(): void
    {
        DB::shouldReceive('connection')->never();
        Http::preventStrayRequests();
        $root = storage_path('framework/testing/api-docs-'.bin2hex(random_bytes(8)));
        mkdir($root, recursive: true);
        config(['scribe.static.output_path' => $root.'/public']);
        try {
            $this->assertSame(0, Artisan::call('scribe:generate', ['--no-interaction' => true,
                '--no-upgrade-check' => true, '--force' => true, '--scribe-dir' => $root.'/cache']));
            $spec = Yaml::parseFile($root.'/public/openapi.yaml');
            $collection = json_decode(file_get_contents($root.'/public/collection.json'), true, flags: JSON_THROW_ON_ERROR);
            $expected = 0;
            foreach (Route::getRoutes() as $route) {
                if (! str_starts_with($route->uri(), 'api/v1/')) {
                    continue;
                }
                $endpoint = ExtractedEndpointData::fromRoute($route);
                foreach ($endpoint->httpMethods as $method) {
                    $path = $spec['paths']['/'.$endpoint->uri][strtolower($method)] ?? null;
                    $this->assertIsArray($path, $method.' '.$endpoint->uri);
                    $protected = in_array('auth:web', $route->gatherMiddleware(), true);
                    $this->assertSame($protected ? [['staffSession' => []]] : [], $path['security']);
                    $expected++;
                }
            }
            $count = 0;
            $walk = function (array $items) use (&$walk, &$count): void {
                foreach ($items as $item) {
                    if (isset($item['request'])) {
                        $count++;
                    } else {
                        $walk($item['item']);
                    }
                }
            };
            $walk($collection['item']);
            $this->assertSame($expected, $count);
            $this->assertSame('noauth', $collection['auth']['type']);
            $this->assertSame('cookie', $spec['components']['securitySchemes']['staffSession']['in']);
            $this->assertSame('csrf_token', $collection['variable'][1]['key']);
            $this->assertSame('idempotency_key', $collection['variable'][2]['key']);
            $this->assertStringContainsString('pm.collectionVariables.set', implode('', $collection['event'][0]['script']['exec']));
            $this->assertArrayHasKey('get', $spec['paths']['/api/v1/auth/csrf-token']);
            $login = $spec['paths']['/api/v1/auth/login']['post'];
            $this->assertArrayHasKey('200', $login['responses']);
            $this->assertContains('password', $login['requestBody']['content']['application/json']['schema']['required']);
            $html = file_get_contents($root.'/public/index.html');
            $this->assertStringContainsString('Frontend integration guide', $html);
            $this->assertStringContainsString('SameSite=Lax', $html);
            $this->assertStringNotContainsString('SQLSTATE', $html);
            Http::assertNothingSent();
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
