<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditTrail;
use App\Services\PatientRecordManagement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditSecurityEvents
{
    public function __construct(private AuditTrail $audit) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();
        $action = match ($request->route()?->getName()) {
            'api.v1.prescriptions.store' => 'prescription.created',
            'api.v1.prescriptions.update' => 'prescription.updated',
            'api.v1.prescriptions.sign' => 'prescription.signed',
            'api.v1.prescriptions.amend' => 'prescription.amended',
            'api.v1.prescriptions.void' => 'prescription.voided',
            'api.v1.prescriptions.stop' => 'prescription.stopped',
            'api.v1.prescriptions.complete' => 'prescription.completed',
            'api.v1.prescriptions.index' => 'prescription.listed',
            'api.v1.prescriptions.show' => 'prescription.viewed',
            'api.v1.prescriptions.history' => 'prescription.history_viewed',
            'api.v1.patients.history' => 'patient.history_viewed',
            'api.v1.clinical.store' => 'clinical.created',
            'api.v1.clinical.update' => 'clinical.updated',
            'api.v1.clinical.sign' => 'clinical.signed',
            'api.v1.clinical.amend' => 'clinical.amended',
            'api.v1.clinical.void' => 'clinical.voided',
            'api.v1.clinical.index' => 'clinical.listed',
            'api.v1.clinical.show' => 'clinical.viewed',
            'api.v1.clinical.history' => 'clinical.history_viewed',
            'api.v1.auth.login' => 'auth.login',
            'api.v1.auth.logout' => 'auth.logout',
            'api.v1.auth.password.reset' => 'staff.password_reset',
            'api.v1.staff-management.store' => 'staff.created',
            'api.v1.staff-management.update' => 'staff.updated',
            'api.v1.staff-management.password-reset' => 'staff.recovery_issued',
            'api.v1.audit-events.index' => 'audit.viewed',
            'api.v1.files.store' => 'file.uploaded',
            'api.v1.files.index' => 'file.listed',
            'api.v1.files.show' => 'file.viewed',
            'api.v1.files.download' => 'file.download_authorized',
            'api.v1.patients.store' => 'patient.created',
            'api.v1.patients.update' => 'patient.updated',
            'api.v1.patients.index' => 'patient.listed',
            'api.v1.patients.show' => 'patient.viewed',
            'api.v1.patients.duplicates' => 'patient.duplicates_checked',
            'api.v1.encounters.store', 'api.v1.appointments.arrive' => 'encounter.opened',
            'api.v1.encounters.assign' => 'encounter.assigned',
            'api.v1.encounters.reassign' => 'encounter.reassigned',
            'api.v1.encounters.accept' => 'encounter.accepted',
            'api.v1.encounters.complete' => 'encounter.completed',
            'api.v1.encounters.cancel' => 'encounter.cancelled',
            'api.v1.encounters.index', 'api.v1.queues.index' => 'encounter.listed',
            'api.v1.encounters.show' => 'encounter.viewed',
            'api.v1.encounters.history', 'api.v1.encounters.assignments' => 'encounter.history_viewed',
            'api.v1.appointments.store' => 'appointment.created',
            'api.v1.appointments.update' => 'appointment.updated',
            'api.v1.appointments.confirm' => 'appointment.confirmed',
            'api.v1.appointments.cancel' => 'appointment.cancelled',
            'api.v1.appointments.no-show' => 'appointment.no_show',
            'api.v1.appointments.index' => 'appointment.listed',
            'api.v1.appointments.show' => 'appointment.viewed',
            'api.v1.appointments.history' => 'appointment.history_viewed',
            'api.v1.patients.records.flags.index' => 'patient.flags_viewed',
            'api.v1.patients.records.cards.index' => 'patient.card_history_viewed',
            'api.v1.patients.records.access.show' => 'patient.access_state_viewed',
            'api.v1.patients.records.merges.index' => 'patient.merge_requests_viewed',
            default => PatientRecordManagement::ACTIONS[str_replace('api.v1.patients.records.', '', $request->route()?->getName() ?? '')][1] ?? null,
        };

        $route = $request->route()?->getName() ?? '';
        if ((str_starts_with($route,'api.v1.reminders.') || str_starts_with($route,'api.v1.notifications.')) && $status>=400) {
            $notification=str_starts_with($route,'api.v1.notifications.');
            $suffix=substr($route,strrpos($route,'.')+1);
            $action=\App\Services\ReminderWorkflows::isCommand($route)?$suffix:'viewed';
            $this->audit->record(($notification?'notification.':'reminder.').$action,$request->user('web')?->id,$notification?'notification':'reminder',
                outcome:$status>=500?'failed':'denied',httpStatus:$status);
            return $response;
        }
        if (str_starts_with($route, 'api.v1.hmo.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = isset(\App\Services\HmoWorkflows::PERMISSIONS[$suffix]) ? $suffix : 'viewed';
            $this->audit->record('hmo.'.$action, $request->user('web')?->id,
                $request->route('patient') === null ? 'hmo_catalog' : 'hmo_account',
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.billing.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = isset(\App\Services\BillingWorkflows::PERMISSIONS[$suffix]) ? $suffix : 'viewed';
            $catalog = str_contains($route, '.catalog.') || in_array($suffix, ['service','price'], true);
            $this->audit->record('billing.'.$action, $request->user('web')?->id, $catalog ? 'billing_catalog' : 'billing_account',
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.medications.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = isset(\App\Services\MedicationFulfillment::PERMISSIONS[$suffix]) ? $suffix
                : ($suffix === 'history' ? 'history_viewed' : ($suffix === 'index' || $suffix === 'options' ? 'listed' : 'viewed'));
            $target = $request->route('medication');
            $this->audit->record('medication.'.$action, $request->user('web')?->id, 'medication',
                is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.stock.') && $status >= 400) {
            $catalog = str_starts_with($route, 'api.v1.stock.catalog.') || str_ends_with($route, '.access') || str_ends_with($route, '.accesses') || str_ends_with($route, '.access-history');
            $suffix = substr($route, strrpos($route, '.') + 1);
            $stockAction = match ($suffix) {
                'store' => $catalog ? 'catalog_changed' : 'created', 'update' => $catalog ? 'catalog_changed' : 'updated',
                'submit' => 'submitted', 'approve' => 'posted', 'reject' => 'rejected', 'cancel' => 'cancelled',
                'access' => 'access_changed', 'history', 'access-history' => 'history_viewed', 'reconciliation' => 'reconciled',
                'index', 'options', 'balances', 'movements', 'my-stores', 'accesses' => 'listed', default => 'viewed',
            };
            $target = $request->route($catalog ? 'stockRecord' : 'stockOperation');
            $this->audit->record('stock.'.$stockAction, $request->user('web')?->id, $catalog ? 'stock_catalog' : 'stock_operation',
                is_string($target) && ctype_digit($target) ? (int) $target : null, [],
                in_array($status, [401,403,404,419], true) ? 'denied' : 'failed', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.investigations.results.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = \App\Services\InvestigationResults::ACTIONS[$suffix] ?? match ($suffix) {
                'index', 'item-index', 'options' => 's_listed', 'history' => '_history_viewed',
                'download' => '_attachment_download_authorized', default => '_viewed',
            };
            $action = isset(\App\Services\InvestigationResults::ACTIONS[$suffix]) ? '_'.$action : $action;
            $target = $request->route('investigationResult');
            $this->audit->record('investigation.result'.$action, $request->user('web')?->id, 'investigation_result',
                is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.investigations.') && ! str_starts_with($route, 'api.v1.investigations.catalog.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = \App\Services\InvestigationRequests::ACTIONS[$suffix] ?? match ($suffix) {
                'index', 'options' => 'orders_listed', 'queue' => 'queue_viewed', 'history' => 'history_viewed', default => 'order_viewed',
            };
            $target = $request->route('investigationOrder');
            $this->audit->record('investigation.'.$action, $request->user('web')?->id, 'investigation_order', is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.investigations.catalog.') && $status >= 400) {
            $target = $request->route('investigationTest');
            $this->audit->record('investigation.'.match (substr($route, strrpos($route, '.') + 1)) {
                'index', 'options' => 'listed', 'schemas', 'schema' => 'schemas_viewed', default => 'viewed',
            }, $request->user('web')?->id, 'investigation_test', is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }
        if (str_starts_with($route, 'api.v1.care-programs.') && $status >= 400) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $target = $request->route('careProgram');
            $this->audit->record('care.'.match ($suffix) {
                'store' => 'opened', 'complete' => 'completed', 'birth' => 'birth_recorded', 'link' => 'birth_linked',
                'index' => 'listed', default => 'viewed',
            }, $request->user('web')?->id, 'care_program', is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);
            return $response;
        }
        if (str_starts_with($route, 'api.v1.care.')) {
            $action = 'clinical.'.match (substr($route, strrpos($route, '.') + 1)) {
                'store' => 'created', 'update' => 'updated', 'sign' => 'signed', 'amend' => 'amended', 'void' => 'voided',
                'history' => 'history_viewed', default => 'viewed',
            };
        }
        if (str_starts_with($route, 'api.v1.admissions.')) {
            $suffix = substr($route, strrpos($route, '.') + 1);
            $action = 'admission.'.(\App\Services\AdmissionFlow::ACTIONS[$suffix][1]
                ?? match ($suffix) { 'index' => 'listed', 'history', 'allocations' => 'history_viewed', default => 'viewed' });
        } elseif (str_starts_with($route, 'api.v1.locations.')) {
            $action = 'location.'.match (substr($route, strrpos($route, '.') + 1)) {
                'store' => 'created', 'update' => 'updated', 'index' => 'listed', default => 'viewed',
            };
        }

        if ($action !== null && $status >= 400 && (str_starts_with($action, 'admission.') || str_starts_with($action, 'location.'))) {
            $location = str_starts_with($action, 'location.');
            $target = $request->route($location ? 'location' : 'admission');
            $this->audit->record($action, $request->user('web')?->id,
                $location ? (str_contains($route, '.beds.') ? 'bed' : 'ward') : 'admission',
                is_string($target) && ctype_digit($target) ? (int) $target : null,
                outcome: $status >= 500 ? 'failed' : 'denied', httpStatus: $status);

            return $response;
        }

        if ($action !== null && $status >= 400) {
            $target = $request->route('user');
            $fileId = $request->route('file');
            $isClinical = str_starts_with($action, 'clinical.') || str_starts_with($action, 'prescription.');
            $clinicalId = $request->route('clinicalRecord');
            $isFile = str_starts_with($action, 'file.');
            $isPatient = str_starts_with($action, 'patient.');
            $isAppointment = str_starts_with($action, 'appointment.');
            $isEncounter = str_starts_with($action, 'encounter.');
            $encounterId = $request->route('encounter');
            $appointmentId = $request->route('appointment');
            $patientId = $request->route('patient');
            $this->audit->record(
                action: $action,
                actorId: $request->user('web')?->id,
                subjectType: $isClinical ? 'clinical' : ($isEncounter ? 'encounter' : ($isAppointment ? 'appointment' : ($isPatient ? 'patient' : ($isFile ? 'file' : ($action === 'audit.viewed' ? 'audit' : 'staff'))))),
                subjectId: $isClinical && is_string($clinicalId) && ctype_digit($clinicalId) ? (int) $clinicalId : ($isEncounter && is_string($encounterId) && ctype_digit($encounterId) ? (int) $encounterId
                    : ($isAppointment && is_string($appointmentId) && ctype_digit($appointmentId) ? (int) $appointmentId
                    : ($isPatient && is_string($patientId) && ctype_digit($patientId) ? (int) $patientId
                    : ($isFile && is_string($fileId) && ctype_digit($fileId) ? (int) $fileId
                    : ($target instanceof User ? $target->id : null))))),
                outcome: $status >= 500 ? 'failed' : 'denied',
                httpStatus: $status,
            );
        }

        return $response;
    }
}
