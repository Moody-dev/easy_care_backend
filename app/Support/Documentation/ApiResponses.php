<?php

namespace App\Support\Documentation;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

class ApiResponses extends Strategy
{
    public function __invoke(ExtractedEndpointData $endpointData, array $settings = []): array
    {
        $responses = [];
        if ($endpointData->metadata->authenticated) {
            $responses[] = $this->example(401, ['message' => 'Please sign in to continue.', 'code' => 'unauthenticated'], 'No valid staff session');
            $responses[] = $this->example(403, ['message' => 'You do not have permission to perform this action.', 'code' => 'forbidden'], 'Permission or workflow access denied');
        }
        if (! in_array('GET', $endpointData->httpMethods, true)) {
            $responses[] = $this->example(419, ['message' => 'Your session has expired. Please sign in again.', 'code' => 'session_expired'], 'Missing or expired session/CSRF protection');
            $responses[] = $this->example(422, ['message' => 'The submitted data is invalid.', 'code' => 'validation_failed',
                'errors' => ['field_name' => ['Example validation message; actual field names depend on this request.']]], 'Validation error envelope');
        }
        $responses[] = $this->example(429, ['message' => 'Too many requests. Please try again later.', 'code' => 'too_many_requests'], 'Rate limited; respect Retry-After');
        $name = $endpointData->route->getName();
        if ($name === 'api.v1.patients.number-reservations.store') {
            $body = ['data' => ['id' => 1, 'category_id' => 1, 'mrn' => 'PVT-01',
                'number_reservation_token' => str_repeat('a', 64), 'numbering_mode' => 'reserved', 'read_only' => true]];
            $responses[] = $this->example(201, $body, 'Synthetic new reservation; replace example IDs/token with returned values');
            $responses[] = $this->example(200, $body, 'Same-key retry returns the original reservation');
        }
        if ($name === 'api.v1.health') {
            $responses[] = $this->example(200, ['data' => ['application' => 'EasyCare API Testing', 'api_version' => 'v1', 'status' => 'ready']], 'Database readiness');
        }
        if ($name === 'api.v1.auth.csrf') {
            $responses[] = $this->example(200, ['data' => ['csrf_token' => 'SYNTHETIC_CSRF_TOKEN_REPLACE_WITH_SERVER_VALUE']], 'Session bootstrap; retain response cookies');
        }
        if ($name === 'api.v1.auth.logout') {
            $responses[] = ['status' => 204, 'content' => '', 'description' => 'Signed out; no response body'];
        }
        if (in_array($name, ['api.v1.auth.login', 'api.v1.auth.me'], true)) {
            $responses[] = $this->example(200, ['data' => ['user' => [
                'id' => 1, 'username' => 'example.staff', 'staff_code' => 'EXAMPLE-001',
                'first_name' => 'Example', 'last_name' => 'Staff', 'middle_name' => null,
                'name' => 'Example Staff', 'email' => 'staff@example.test', 'is_active' => true,
                'department' => ['id' => 1, 'code' => 'frontdesk', 'name' => 'Frontdesk'],
                'roles' => [['code' => 'frontdesk', 'name' => 'Frontdesk']], 'permissions' => [],
                'created_at' => '2026-11-19T08:00:00.000000Z', 'last_login_at' => '2026-11-19T08:00:00.000000Z',
            ], 'csrf_token' => 'SYNTHETIC_CSRF_TOKEN_REPLACE_WITH_SERVER_VALUE', 'expires_at' => '2026-11-19T10:00:00.000000Z']], 'Synthetic staff session; permissions are account-specific');
        }
        if (in_array($name, ['api.v1.patients.index', 'api.v1.patients.show', 'api.v1.patients.store', 'api.v1.patients.update'], true)) {
            $patient = ['id' => 1, 'mrn' => 'EXAMPLE-0001', 'first_name' => 'Example', 'middle_name' => null,
                'last_name' => 'Patient', 'sex' => null, 'date_of_birth' => null, 'dob_estimated' => false,
                'category' => ['id' => 1, 'code' => 'example', 'name' => 'Example category'], 'version' => 1,
                'created_at' => '2026-11-19T08:00:00.000000Z', 'updated_at' => '2026-11-19T08:00:00.000000Z'];
            if ($name === 'api.v1.patients.index') {
                $body = ['data' => [$patient], 'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
                    'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'links' => [], 'path' => '/api/v1/patients', 'per_page' => 25, 'to' => 1, 'total' => 1]];
            } else {
                $patient += ['email' => null, 'phone' => null, 'marital_status' => null, 'religion' => null,
                    'occupation' => null, 'state_of_origin' => null, 'address' => null, 'life_status' => 'unknown',
                    'deceased_at' => null, 'created_by' => 1, 'contacts' => [], 'identifiers' => []];
                $body = ['data' => $patient];
                if ($name === 'api.v1.patients.show') {
                    $body['meta'] = ['requested_id' => 1, 'canonical_id' => 1];
                }
            }
            $responses[] = $this->example($name === 'api.v1.patients.store' ? 201 : 200, $body, 'Synthetic patient example; use returned links, identifiers and version values');
        }

        return $responses;
    }

    private function example(int $status, array $body, string $description): array
    {
        return ['status' => $status, 'content' => json_encode($body, JSON_THROW_ON_ERROR), 'description' => $description];
    }
}
