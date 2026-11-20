<?php

namespace App\Support\Documentation;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class ApiHeaders extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if (! in_array('GET', $endpointData->httpMethods, true)) {
            $headers += ['Content-Type' => 'application/json', 'X-CSRF-TOKEN' => '{{csrf_token}}'];
        }
        if (ApiMetadata::isCommand($endpointData)) {
            $headers['Idempotency-Key'] = '{{idempotency_key}}';
        }

        return $headers;
    }
}
