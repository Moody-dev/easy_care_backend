<?php

namespace App\Support\Documentation;

use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Writing\OpenApiSpecGenerators\OpenApiGenerator;

class SessionSecurity extends OpenApiGenerator
{
    public function root(array $root, array $groupedEndpoints): array
    {
        $root['components']['securitySchemes']['staffSession'] = ['type' => 'apiKey', 'in' => 'cookie',
            'name' => 'easycare_supabase_session', 'description' => 'Issued by the session login flow. Use the browser/Postman cookie jar; there is no bearer-token login.'];

        return $root;
    }

    public function pathItem(array $pathItem, array $groupedEndpoints, OutputEndpointData $endpoint): array
    {
        $pathItem['security'] = $endpoint->metadata->authenticated ? [['staffSession' => []]] : [];

        return $pathItem;
    }
}
