<?php

namespace App\Support\Documentation;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class ApiMetadata extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $middleware = $endpointData->route->gatherMiddleware();
        $permissions = array_values(array_filter($middleware, fn (string $item): bool => str_starts_with($item, 'can:')));
        $name = substr($endpointData->route->getName(), strlen('api.v1.'));
        $description = 'Route name: '.$endpointData->route->getName().'.';
        if ($permissions !== []) {
            $description .= "\n\nRequired permission checks: ".implode(', ', array_map(fn (string $item): string => substr($item, 4), $permissions)).'. Record visibility and workflow restrictions also apply.';
        }
        if (self::isCommand($endpointData)) {
            $description .= "\n\nRequires an Idempotency-Key. Reuse the same key and payload for a retry; use a new key for a new intent. Supply the current saved version fields shown below. A reason, password confirmation or independent approval may also be required.";
        }

        return ['groupName' => Str::headline(explode('.', $name)[0]),
            'title' => Str::headline(str_replace('.', ' / ', $name)),
            'description' => $description,
            'authenticated' => in_array('auth:web', $middleware, true)];
    }

    public static function methodSource(ExtractedEndpointData $endpoint): string
    {
        $method = $endpoint->method;
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    public static function isCommand(ExtractedEndpointData $endpoint): bool
    {
        return $endpoint->route->getName() === 'api.v1.patients.number-reservations.store'
            || str_contains(self::methodSource($endpoint), '$commands->run(');
    }
}
