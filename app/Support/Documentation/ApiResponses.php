<?php

namespace App\Support\Documentation;

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\EncounterController;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\EncounterResource;
use App\Models\Appointment;
use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientNumbers;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        if ($name === 'api.v1.hmo.registration-insurance') {
            $responses[] = $this->example(200, ['data' => [
                'patient_id' => 1, 'plan_id' => 2, 'member_number' => 'SYN-MEMBER-1', 'captured_by' => 3,
                'captured_at' => '2026-11-24T10:15:00.000000Z', 'coverage_id' => null, 'provider_id' => 4,
            ]], 'Registration insurance. Data is null if absent; coverage_id is null until verified.');
        }
        if ($name === 'api.v1.billing.options') {
            $responses[] = $this->example(200, ['data' => [
                'currency' => 'NGN',
                'money_decimal_places' => 2,
                'rounding' => 'half_up_per_line',
                'payments_processed_by_platform' => false,
                'refunds_processed_by_platform' => false,
                'methods' => ['cash', 'bank_transfer', 'pos', 'other'],
                'independent_change_approval' => true,
                'timezone' => 'Africa/Lagos',
            ]], 'Billing settings, supported external payment methods and approval rules');
        }
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
        if ($name === 'api.v1.auth.password.reset') {
            $responses[] = ['status' => 204, 'content' => '', 'description' => 'Password reset; start a fresh staff session before signing in'];
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
        if ($name === 'api.v1.patients.options') {
            $responses[] = $this->example(200, ['data' => ['categories' => [
                ['id' => 1, 'code' => 'private', 'name' => 'Private', 'numbering_mode' => 'reservation_required', 'number_prefix' => PatientNumbers::PREFIXES['private']],
                ['id' => 2, 'code' => 'walk-in', 'name' => 'Walk-in', 'numbering_mode' => 'reservation_required', 'number_prefix' => PatientNumbers::PREFIXES['walk-in']],
                ['id' => 3, 'code' => 'staff', 'name' => 'Staff', 'numbering_mode' => 'staff_entered', 'number_prefix' => null],
            ]]], 'Example categories; use the returned category IDs. Staff-entered categories have a null number_prefix');
        }
        if (in_array($name, ['api.v1.patients.index', 'api.v1.patients.duplicates', 'api.v1.patients.show', 'api.v1.patients.store', 'api.v1.patients.update'], true)) {
            $patient = ['id' => 1, 'mrn' => 'EXAMPLE-0001', 'first_name' => 'Example', 'middle_name' => null,
                'last_name' => 'Patient', 'sex' => null, 'date_of_birth' => null, 'dob_estimated' => false,
                'category' => ['id' => 1, 'code' => 'example', 'name' => 'Example category'], 'version' => 1,
                'created_at' => '2026-11-19T08:00:00.000000Z', 'updated_at' => '2026-11-19T08:00:00.000000Z'];
            if ($name === 'api.v1.patients.index') {
                $body = ['data' => [$patient], 'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
                    'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'links' => [], 'path' => '/api/v1/patients', 'per_page' => 25, 'to' => 1, 'total' => 1]];
            } elseif ($name === 'api.v1.patients.duplicates') {
                $body = ['data' => [$patient], 'meta' => ['has_more' => false]];
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
            if ($name === 'api.v1.patients.store') {
                $responses[] = $this->example(200, $body, 'Same-key retry returns the saved patient while the saved version is current');
            }
        }

        return [...$responses, ...$this->visitResponses($name)];
    }

    private function visitResponses(string $name): array
    {
        switch ($name) {
            case 'api.v1.appointments.options':
                return [$this->example(200, (new AppointmentController)->options()->getData(true), 'Booking choices and scheduling rules')];
            case 'api.v1.encounters.options':
                return [$this->example(200, (new EncounterController)->options()->getData(true), 'Visit settings and clinician queue options')];
            case 'api.v1.appointments.clinicians':
            case 'api.v1.encounters.clinicians':
                return [$this->example(200, ['data' => [['id' => 7, 'name' => 'Example Clinician']],
                    'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1]], 'Example page of active clinicians matching the search')];
        }

        $appointmentStatus = match ($name) {
            'api.v1.appointments.index', 'api.v1.appointments.show', 'api.v1.appointments.store', 'api.v1.appointments.update' => 'booked',
            'api.v1.appointments.confirm' => 'confirmed',
            'api.v1.appointments.cancel' => 'cancelled',
            'api.v1.appointments.no-show' => 'no_show',
            default => null,
        };
        $encounterStatus = match ($name) {
            'api.v1.appointments.arrive', 'api.v1.encounters.store', 'api.v1.encounters.assign', 'api.v1.encounters.reassign',
            'api.v1.encounters.index', 'api.v1.encounters.show', 'api.v1.queues.index' => 'open',
            'api.v1.encounters.accept' => 'in_progress',
            'api.v1.encounters.complete' => 'closed',
            'api.v1.encounters.cancel' => 'cancelled',
            default => null,
        };
        if ($appointmentStatus === null && $encounterStatus === null) {
            return [];
        }

        $request = Request::create('/');
        $doctor = (new User)->forceFill(['id' => 7, 'name' => 'Example Clinician']);
        $timezone = config('appointments.timezone');
        if ($appointmentStatus !== null) {
            $booking = (new Appointment)->forceFill([
                'id' => 12, 'first_name' => 'Example', 'last_name' => 'Patient', 'phone' => '08000000000',
                'booking_channel' => 'Walk-In', 'visit_type' => 'General Consultation',
                'specialist_name' => null, 'specialist_email' => null, 'status' => $appointmentStatus,
                'patient_id' => 1, 'doctor_id' => 7, 'booked_by' => 4,
                'version' => $name === 'api.v1.appointments.store' ? 1 : 2,
                'starts_at' => '2026-11-21T08:00:00Z', 'ends_at' => '2026-11-21T08:30:00Z',
                'created_at' => '2026-11-21T07:30:00Z', 'updated_at' => '2026-11-21T07:45:00Z',
            ])->setRelation('followUpLink', null)->setRelation('doctor', $doctor);
            $item = (new AppointmentResource($booking))->toArray($request);
            $body = match ($name) {
                'api.v1.appointments.index' => $this->visitPage($item, '/api/v1/appointments') + ['timezone' => $timezone],
                'api.v1.appointments.show' => ['data' => $item, 'meta' => ['timezone' => $timezone]],
                default => ['data' => $item],
            };
        } else {
            $opened = in_array($name, ['api.v1.appointments.arrive', 'api.v1.encounters.store'], true);
            $finished = in_array($encounterStatus, ['closed', 'cancelled'], true);
            $accepted = $encounterStatus === 'in_progress';
            $patient = (new Patient)->forceFill(['id' => 1, 'mrn' => 'PVT-01', 'first_name' => 'Example', 'last_name' => 'Patient', 'version' => 1]);
            $assignment = $opened || $finished ? null : (new DoctorAssignment)->forceFill([
                'id' => 6, 'doctor_id' => 7, 'assigned_by' => 4, 'ended_by' => null,
                'consultation_type' => 'General Consultation', 'status' => $accepted ? 'accepted' : 'assigned',
                'version' => $accepted ? 2 : 1, 'assigned_at' => '2026-11-21T08:05:00Z',
                'accepted_at' => $accepted ? '2026-11-21T08:10:00Z' : null, 'ended_at' => null,
            ])->setRelation('doctor', $doctor);
            $visit = (new Encounter)->forceFill([
                'id' => 19, 'patient_id' => 1, 'appointment_id' => $name === 'api.v1.encounters.store' ? null : 12,
                'category_id' => 1, 'opened_by' => 4, 'visit_type' => 'General Consultation',
                'care_setting' => 'OPD', 'status' => $encounterStatus,
                'version' => match ($name) {
                    'api.v1.appointments.arrive', 'api.v1.encounters.store' => 1,
                    'api.v1.encounters.accept', 'api.v1.encounters.reassign' => 3,
                    'api.v1.encounters.complete' => 4,
                    default => 2,
                },
                'opened_at' => '2026-11-21T08:00:00Z', 'closed_at' => $finished ? '2026-11-21T08:25:00Z' : null,
            ])->setRelation('patient', $patient)->setRelation('currentAssignment', $assignment);
            $item = (new EncounterResource($visit))->toArray($request);
            $body = match ($name) {
                'api.v1.queues.index' => $this->visitPage($item, '/api/v1/queues') + ['timezone' => $timezone],
                'api.v1.encounters.index' => $this->visitPage($item, '/api/v1/encounters') + ['timezone' => $timezone],
                default => ['data' => $item],
            };
        }

        if (in_array($name, ['api.v1.appointments.store', 'api.v1.appointments.arrive', 'api.v1.encounters.store'], true)) {
            return [
                $this->example(201, $body, 'Synthetic new record; use the returned ID and version'),
                $this->example(200, $body, 'Same-key retry returns the saved record while the saved version is current'),
            ];
        }

        return [$this->example(200, $body, 'Synthetic saved record or page; use returned IDs and versions')];
    }

    private function visitPage(array $item, string $path): array
    {
        $paginator = new LengthAwarePaginator([$item], 1, config('api.pagination.per_page'), 1, ['path' => $path]);
        $pagination = $paginator->toArray();

        return [
            'data' => [$item],
            'links' => ['first' => $pagination['first_page_url'], 'last' => $pagination['last_page_url'],
                'prev' => $pagination['prev_page_url'], 'next' => $pagination['next_page_url']],
            'meta' => array_diff_key($pagination, array_flip(['data', 'first_page_url', 'last_page_url', 'prev_page_url', 'next_page_url'])),
        ];
    }

    private function example(int $status, array $body, string $description): array
    {
        return ['status' => $status, 'content' => json_encode($body, JSON_THROW_ON_ERROR), 'description' => $description];
    }
}
