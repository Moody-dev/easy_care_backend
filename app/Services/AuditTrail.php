<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditTrail
{
    public const ACTIONS = [
        'export.generated','export.viewed','export.downloaded','export.printed',
        'dashboard.viewed','report.viewed',
        'reminder.created','reminder.updated','reminder.cancelled','reminder.viewed','reminder.processed',
        'notification.read','notification.unread','notification.snoozed','notification.viewed',
        'hmo.provider-create','hmo.provider-update','hmo.plan-create','hmo.plan-update','hmo.tariff-import','hmo.tariff-review',
        'hmo.coverage-create','hmo.coverage-revoke','hmo.preauth-create','hmo.preauth-revoke','hmo.invoice','hmo.claim-submit','hmo.claim-resubmit',
        'hmo.decision-record','hmo.decision-review','hmo.remittance-create','hmo.remittance-review','hmo.allocation-record','hmo.allocation-review',
        'hmo.catalog_viewed','hmo.viewed','hmo.csv_downloaded','hmo.reconciled',
        'billing.service', 'billing.price', 'billing.performed', 'billing.invoice', 'billing.receipt', 'billing.allocate',
        'billing.credit-request', 'billing.refund-request', 'billing.approve', 'billing.reject',
        'billing.catalog_viewed', 'billing.viewed', 'billing.history_viewed', 'billing.reconciled',
        'medication.authorize', 'medication.dispense', 'medication.return-request', 'medication.return-approve',
        'medication.return-reject', 'medication.administer', 'medication.correct', 'medication.listed', 'medication.viewed', 'medication.history_viewed',
        'stock.catalog_changed', 'stock.access_changed', 'stock.created', 'stock.updated', 'stock.submitted',
        'stock.posted', 'stock.rejected', 'stock.cancelled', 'stock.listed', 'stock.viewed', 'stock.history_viewed', 'stock.reconciled',
        'investigation.result_created', 'investigation.result_updated', 'investigation.result_submitted', 'investigation.result_verified',
        'investigation.result_released', 'investigation.result_amended', 'investigation.result_voided', 'investigation.results_listed',
        'investigation.result_viewed', 'investigation.result_history_viewed', 'investigation.result_attachment_download_authorized',
        'investigation.requested', 'investigation.collected', 'investigation.processing', 'investigation.cancelled',
        'investigation.orders_listed', 'investigation.order_viewed', 'investigation.queue_viewed', 'investigation.history_viewed',
        'investigation.published', 'investigation.listed', 'investigation.viewed', 'investigation.schemas_viewed',
        'care.opened','care.completed','care.birth_recorded','care.birth_linked','care.listed','care.viewed','care.form_published',
        'admission.admitted', 'admission.allocated', 'admission.transferred', 'admission.released',
        'admission.discharge_authorized', 'admission.discharged', 'admission.listed', 'admission.viewed', 'admission.history_viewed',
        'location.created', 'location.updated', 'location.listed', 'location.viewed',
        'prescription.created', 'prescription.updated', 'prescription.signed', 'prescription.amended', 'prescription.voided',
        'prescription.stopped', 'prescription.completed', 'prescription.listed', 'prescription.viewed', 'prescription.history_viewed',
        'patient.history_viewed',
        'clinical.created', 'clinical.updated', 'clinical.signed', 'clinical.amended', 'clinical.voided',
        'clinical.listed', 'clinical.viewed', 'clinical.history_viewed',
        'appointment.attended', 'encounter.opened', 'encounter.assigned', 'encounter.reassigned', 'encounter.accepted',
        'encounter.completed', 'encounter.cancelled', 'encounter.listed', 'encounter.viewed', 'encounter.history_viewed',
        'appointment.created', 'appointment.updated', 'appointment.rescheduled', 'appointment.confirmed',
        'appointment.cancelled', 'appointment.no_show', 'appointment.listed', 'appointment.viewed', 'appointment.history_viewed',
        'staff.created', 'staff.updated', 'staff.recovery_issued', 'staff.password_reset',
        'role.permission_granted', 'role.permission_revoked',
        'auth.login', 'auth.logout', 'audit.viewed', 'command.replayed',
        'file.uploaded', 'file.listed', 'file.viewed', 'file.download_authorized', 'file.integrity_checked',
        'patient.created', 'patient.updated', 'patient.listed', 'patient.viewed', 'patient.duplicates_checked',
        'patient.flagged', 'patient.flag_resolved', 'patient.flags_viewed', 'patient.card_changed', 'patient.card_history_viewed',
        'patient.access_restricted', 'patient.access_restored', 'patient.access_state_viewed',
        'patient.merge_requested', 'patient.merge_approved', 'patient.merge_rejected', 'patient.merge_cancelled', 'patient.merge_requests_viewed',
    ];

    /** @param array<string, mixed> $summary */
    public function record(
        string $action,
        ?int $actorId,
        string $subjectType,
        ?int $subjectId = null,
        array $summary = [],
        string $outcome = 'succeeded',
        string $channel = 'http',
        ?int $httpStatus = null,
    ): void {
        if (! in_array($action, self::ACTIONS, true)
            || ! in_array($outcome, ['succeeded', 'denied', 'failed'], true)
            || ! in_array($channel, ['http', 'console'], true)
            || ! in_array($subjectType, ['document_export', 'business_report', 'reminder', 'notification', 'hmo_catalog', 'hmo_account', 'billing_catalog', 'billing_account', 'medication', 'stock_catalog', 'stock_operation', 'staff', 'role', 'audit', 'file', 'patient', 'appointment', 'encounter', 'clinical', 'admission', 'ward', 'bed', 'care_program', 'care_form', 'investigation_test', 'investigation_order', 'investigation_result'], true)) {
            throw new InvalidArgumentException('Unknown audit event classification.');
        }

        $this->validateSummary($action, $summary);
        $requestId = (string) Str::uuid();
        if ($channel === 'http' && app()->bound('request')) {
            $request = request();
            $requestId = $request->attributes->get('audit_request_id', $requestId);
            $request->attributes->set('audit_request_id', $requestId);
        }

        DB::table('audit_events')->insert([
            'action' => $action,
            'actor_id' => $actorId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'outcome' => $outcome,
            'channel' => $channel,
            'request_id' => $requestId,
            'http_status' => $httpStatus,
            'safe_summary' => json_encode((object) $summary, JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }

    /** @param array<string, mixed> $summary */
    private function validateSummary(string $action, array $summary): void
    {
        $allowed = match ($action) {
            'dashboard.viewed','report.viewed' => ['report_key'],
            'reminder.created','reminder.updated','reminder.cancelled' => ['version'],
            'reminder.processed' => ['delivered_count','suppressed_count'],
            'hmo.coverage-create','hmo.coverage-revoke','hmo.preauth-create','hmo.preauth-revoke','hmo.invoice','hmo.claim-submit','hmo.claim-resubmit',
            'hmo.decision-record','hmo.decision-review','hmo.allocation-record','hmo.allocation-review'=>['version','record_id'],
            'billing.performed', 'billing.invoice', 'billing.receipt', 'billing.allocate', 'billing.credit-request',
            'billing.refund-request', 'billing.approve', 'billing.reject' => ['version', 'record_id'],
            'medication.authorize', 'medication.dispense', 'medication.return-request', 'medication.return-approve',
            'medication.return-reject', 'medication.administer', 'medication.correct' => ['version', 'record_id'],
            'stock.catalog_changed', 'stock.access_changed', 'stock.created', 'stock.updated', 'stock.submitted',
            'stock.posted', 'stock.rejected', 'stock.cancelled' => ['version', 'record_id'],
            'investigation.result_created', 'investigation.result_updated', 'investigation.result_submitted', 'investigation.result_verified',
            'investigation.result_released', 'investigation.result_amended', 'investigation.result_voided' => ['version', 'record_id'],
            'care.form_published', 'investigation.published', 'investigation.requested', 'investigation.collected', 'investigation.processing', 'investigation.cancelled' => ['version','record_id'],
            'admission.admitted', 'admission.allocated', 'admission.transferred', 'admission.released',
            'admission.discharge_authorized', 'admission.discharged' => ['version', 'record_id'],
            'staff.created' => ['role_codes', 'department_id', 'is_active', 'version'],
            'staff.updated' => ['changed_fields', 'previous_role_codes', 'role_codes', 'previous_department_id',
                'department_id', 'previous_is_active', 'is_active', 'version', 'sessions_revoked'],
            'staff.recovery_issued', 'staff.password_reset' => ['version'],
            'role.permission_granted', 'role.permission_revoked' => ['permission_code', 'changed'],
            'file.uploaded' => ['byte_size', 'mime_type'],
            'file.integrity_checked' => ['checked', 'missing', 'corrupt', 'orphaned'],
            'patient.created' => ['version', 'category_id', 'contact_count', 'duplicates_acknowledged'],
            'patient.updated' => ['version', 'changed_fields', 'contacts_changed'],
            'prescription.created', 'prescription.updated', 'prescription.signed', 'prescription.amended', 'prescription.voided',
            'prescription.stopped', 'prescription.completed',
            'clinical.created', 'clinical.updated', 'clinical.signed', 'clinical.amended', 'clinical.voided',
            'patient.flagged', 'patient.flag_resolved', 'patient.card_changed', 'patient.access_restricted', 'patient.access_restored',
            'patient.merge_requested', 'patient.merge_approved', 'patient.merge_rejected', 'patient.merge_cancelled',
            'appointment.created', 'appointment.updated', 'appointment.rescheduled', 'appointment.confirmed',
            'appointment.attended', 'encounter.opened', 'encounter.assigned', 'encounter.reassigned', 'encounter.accepted',
            'encounter.completed', 'encounter.cancelled', 'appointment.cancelled', 'appointment.no_show' => ['version', 'record_id'],
            default => [],
        };

        foreach ($summary as $key => $value) {
            $valid = in_array($key, $allowed, true) && match ($key) {
                'report_key' => in_array($value,['dashboard','options','activity','trend','inventory','positions','movements','billing','accounts','hmo'],true),
                'department_id', 'previous_department_id' => $value === null || (is_int($value) && $value > 0),
                'category_id', 'record_id' => is_int($value) && $value > 0,
                'byte_size', 'checked', 'missing', 'corrupt', 'orphaned', 'contact_count', 'delivered_count', 'suppressed_count' => is_int($value) && $value >= 0,
                'mime_type' => in_array($value, ['application/pdf', 'image/jpeg', 'image/png'], true),
                'version' => is_int($value) && $value > 0,
                'is_active', 'previous_is_active', 'sessions_revoked', 'changed', 'contacts_changed', 'duplicates_acknowledged' => is_bool($value),
                'permission_code' => is_string($value) && array_key_exists($value, config('authorization.permissions')),
                'role_codes', 'previous_role_codes' => is_array($value) && array_is_list($value)
                    && count($value) <= 100 && collect($value)->every(fn ($code): bool => is_string($code)
                        && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $code) === 1),
                'changed_fields' => is_array($value) && array_is_list($value)
                    && collect($value)->every(fn ($field): bool => is_string($field) && in_array($field,
                        $action === 'patient.updated' ? [...PatientRegistry::DEMOGRAPHICS, 'contacts']
                            : ['first_name', 'last_name', 'middle_name', 'email', 'staff_code', 'department_id', 'is_active', 'role_codes'], true)),
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Unsafe audit summary field.');
            }
        }
    }
}
