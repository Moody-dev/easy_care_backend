<?php

namespace App\Support\Documentation;

use App\Services\StockOperations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\GetFromFormRequestBase;
use ReflectionClass;
use ReflectionFunctionAbstract;

class ApiParameters extends GetFromFormRequestBase
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $this->endpointData = $endpointData;
        $query = in_array('GET', $endpointData->httpMethods, true);
        if (($settings['location'] ?? 'body') !== ($query ? 'query' : 'body')) {
            return [];
        }

        return parent::__invoke($endpointData, $settings) ?? [];
    }

    public function getParametersFromFormRequest(ReflectionFunctionAbstract $method, Route $route): array
    {
        $reflection = $this->getFormRequestReflectionClass($method);
        if ($reflection === null) {
            return [];
        }
        $variants = str_contains($route->uri(), '{catalog}')
            ? explode('|', $route->wheres['catalog']) : [null];
        $rules = [];
        $requiredIn = [];
        $presentIn = [];
        foreach ($variants as $variant) {
            $uri = preg_replace_callback('/\{([^}]+)\}/', fn (array $match): string => $match[1] === 'catalog' ? $variant : '1', $route->uri());
            $className = $reflection->getName();
            $formRequest = $className::createFrom(Request::create('/'.$uri, $route->methods()[0]));
            $boundRoute = clone $route;
            $boundRoute->bind($formRequest);
            $formRequest->setRouteResolver(fn () => $boundRoute);
            foreach ($this->getRouteValidationRules($formRequest) as $key => $fieldRules) {
                $rules[$key] ??= $fieldRules;
                $presentIn[$key][] = $variant;
                if (is_array($fieldRules) && in_array('required', $fieldRules, true)) {
                    $requiredIn[$key][] = $variant;
                }
            }
        }
        $customData = [];
        if ($reflection->getShortName() === 'StorePatientRequest') {
            $customData = [
                'mrn' => ['description' => 'Required only for categories using staff-entered numbers. For Private/Walk-in, omit or echo the exact reserved number; it is read-only.'],
                'number_reservation_token' => ['description' => 'Required for Private/Walk-in. Obtain it from POST /api/v1/patients/number-reservations using the same staff session.'],
            ];
        }
        if (count($variants) > 1) {
            foreach ($rules as $key => &$fieldRules) {
                $required = $requiredIn[$key] ?? [];
                if (count($required) !== count($variants)) {
                    $fieldRules = array_values(array_filter($fieldRules, fn (mixed $rule): bool => $rule !== 'required'));
                }
                $description = 'Applies to catalog: '.implode(', ', $presentIn[$key]).'.';
                if ($required !== []) {
                    $description .= ' Required for: '.implode(', ', $required).'.';
                }
                $customData[$key] = ['description' => $description];
            }
            unset($fieldRules);
        }

        return $this->normaliseArrayAndObjectParameters($this->getParametersFromValidationRules($rules, $customData));
    }

    protected function getFormRequestReflectionClass(ReflectionFunctionAbstract $method): ?ReflectionClass
    {
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                return new ReflectionClass($type->getName());
            }
        }
        $endpoint = $this->endpointData;
        preg_match_all('/app\((\w+Request)::class\)/', ApiMetadata::methodSource($endpoint), $matches);
        $names = array_values(array_unique($matches[1]));
        if (count($names) > 1 && in_array('StockCatalogCommandRequest', $names, true)) {
            $request = Request::create('/'.$endpoint->uri, $endpoint->httpMethods[0]);
            $request->setRouteResolver(fn () => $endpoint->route);
            $names = [StockOperations::isCatalog($request) ? 'StockCatalogCommandRequest' : 'StockOperationCommandRequest'];
        }
        if ($names === []) {
            return null;
        }
        if (count($names) !== 1) {
            throw new \LogicException('Documentation must resolve a single command request for '.$endpoint->uri);
        }
        $source = file_get_contents($method->getFileName());
        if (! preg_match('/use (App\\\\Http\\\\Requests\\\\[^;]*\\\\'.preg_quote($names[0], '/').');/', $source, $match)) {
            throw new \LogicException('Documentation could not resolve '.$names[0]);
        }

        return new ReflectionClass($match[1]);
    }

    protected function isFormRequestMeantForThisStrategy(ReflectionClass $formRequestReflectionClass): bool
    {
        return true;
    }

    protected function getCustomParameterData(FormRequest $formRequest): array
    {
        return [];
    }
}
