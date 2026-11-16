<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\InvestigationOrderResource;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\InvestigationRevision;
use App\Models\InvestigationTest;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use stdClass;

class InvestigationRequests
{
    public const ACTIONS = ['store' => 'requested', 'collect' => 'collected', 'process' => 'processing', 'cancel' => 'cancelled'];
    public const LOAD = ['items.schema', 'patient'];

    public function __construct(private AuditTrail $audit, private InvestigationDefinitions $definitions) {}

    public static function isCommand(string $route): bool
    {
        return str_starts_with($route, 'api.v1.investigations.requests.') && array_key_exists(substr($route, strrpos($route, '.') + 1), self::ACTIONS);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        abort_unless($actor->is_active, 403);
        foreach (['patients.view', 'encounters.view', 'investigations.view'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        $action = self::action($request);
        $orderId = $request->route('investigationOrder');
        $visitId = $action === 'store' ? $request->route('encounter') : InvestigationOrder::query()->whereKey($orderId)->value('encounter_id');
        $patientId = Encounter::query()->whereKey($visitId)->value('patient_id');
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($patientId);
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visitId);
        $order = $action === 'store' ? null : InvestigationOrder::query()->visibleTo($actor)->lockForUpdate()->findOrFail($orderId);
        if ($action === 'store') {
            Gate::forUser($actor)->authorize('investigations.request');
            Gate::forUser($actor)->authorize('investigations.catalog.view');
            abort_unless($actor->roles()->where('code', 'doctor')->exists(), 403);
            $assignment = $visit->assignments()->current()->lockForUpdate()->first();
            abort_unless($assignment !== null && $assignment->doctor_id === $actor->id, 403);
            $request->attributes->set('investigation_assignment', $assignment);
        } else {
            $item = $order->items()->lockForUpdate()->findOrFail($request->route('investigationItem'));
            if ($action === 'cancel') {
                $ownDoctor = $order->ordered_by === $actor->id && $actor->hasPermission('investigations.request') && $actor->roles()->where('code', 'doctor')->exists();
                $diagnostics = $actor->hasPermission('investigations.cancel') && $actor->roles()->where('code', 'diagnostics-and-radiologist')->exists();
                abort_unless($ownDoctor || $diagnostics, 403);
            } else {
                Gate::forUser($actor)->authorize($action === 'collect' ? 'investigations.collect' : 'investigations.process');
                abort_unless($actor->roles()->where('code', 'diagnostics-and-radiologist')->exists(), 403);
            }
            $request->attributes->set('investigation_item', $item);
        }
        $request->attributes->set('investigation_visit', $visit);
        $request->attributes->set('investigation_order', $order);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): InvestigationOrder
    {
        $visit = $request->attributes->get('investigation_visit');
        $this->version($visit->version, $data['encounter_version']);
        if (! in_array($visit->status, ['open', 'in_progress'], true)) {
            throw new WriteConflict('investigation_visit_closed', 'Closed visits are read-only.');
        }
        $time = now('UTC');
        if ($time->lessThan($visit->opened_at)) {
            throw new WriteConflict('investigation_clock_conflict', 'The server time precedes a saved visit event.');
        }
        $action = self::action($request);
        $item = null;
        if ($action === 'store') {
            $patient = Patient::query()->findOrFail($visit->patient_id);
            $this->version($patient->version, $data['patient_version']);
            $assignment = $request->attributes->get('investigation_assignment');
            if ($assignment->id !== (int) $data['assignment_id']) {
                throw new WriteConflict('assignment_conflict', 'The current clinician assignment changed. Reload the visit.');
            }
            $this->version($assignment->version, $data['assignment_version']);
            if ($time->lessThan($assignment->assigned_at) || $assignment->accepted_at !== null && $time->lessThan($assignment->accepted_at)) {
                throw new WriteConflict('investigation_clock_conflict', 'The server time precedes the clinician assignment.');
            }
            $tests = [];
            $rows = collect($data['items'])->sortBy('test_id');
            foreach ($rows as $row) {
                $test = InvestigationTest::query()->with('service')->lockForUpdate()->findOrFail($row['test_id']);
                $this->version($test->version, $row['test_version']);
                $schema = $test->schemas()->where('version', $test->version)->lockForUpdate()->find($row['schema_id']);
                if (! $test->is_active || ! $test->service->is_active || $schema === null || $schema->published_at->greaterThan($time)) {
                    throw new WriteConflict('investigation_definition_unavailable', 'Choose the current active approved test definition.');
                }
                $this->definitions->validate($schema->schema_json);
                $tests[] = [$test, $schema, $row];
            }
            $order = new InvestigationOrder;
            $order->forceFill(['patient_id' => $patient->id, 'encounter_id' => $visit->id, 'ordered_by' => $actor->id,
                'assignment_id' => $assignment->id, 'assignment_version' => $assignment->version, 'version' => 1,
                'requested_at' => $time, 'last_changed_at' => $time, 'clinical_context' => $data['clinical_context'] ?? null, 'reason' => $data['reason']])->save();
            foreach ($tests as [$test, $schema, $row]) {
                $item = new InvestigationItem;
                $item->forceFill(['order_id' => $order->id, 'encounter_id' => $visit->id, 'test_id' => $test->id, 'schema_id' => $schema->id,
                    'department' => $test->department, 'priority' => $row['priority'], 'status' => 'requested', 'version' => 1, 'last_event_at' => $time])->save();
            }
            $item = null;
        } else {
            $order = $request->attributes->get('investigation_order');
            $item = $request->attributes->get('investigation_item');
            $this->version($order->version, $data['order_version']);
            $this->version($item->version, $data['version']);
            if ($time->lessThan($order->last_changed_at) || $time->lessThan($item->last_event_at)) {
                throw new WriteConflict('investigation_clock_conflict', 'The server time precedes a saved investigation event.');
            }
            $allowed = match ($action) {
                'collect' => $item->department === 'laboratory' && $item->status === 'requested',
                'process' => $item->department === 'laboratory' ? $item->status === 'collected' : $item->status === 'requested',
                'cancel' => in_array($item->status, ['requested', 'collected'], true),
            };
            if (! $allowed) {
                throw new WriteConflict('investigation_transition_conflict', 'This saved item cannot make that transition. Reload its current status.');
            }
            $changes = ['status' => self::ACTIONS[$action], 'version' => $item->version + 1, 'last_event_at' => $time];
            $changes += match ($action) {
                'collect' => ['specimen_ref' => $data['specimen_ref'], 'collected_by' => $actor->id, 'collected_at' => $time],
                'process' => ['processing_by' => $actor->id, 'processing_at' => $time],
                'cancel' => ['cancelled_by' => $actor->id, 'cancelled_at' => $time, 'cancellation_reason' => $data['reason']],
            };
            $item->forceFill($changes)->save();
            $order->forceFill(['version' => $order->version + 1, 'last_changed_at' => $time])->save();
        }
        $order = $order->fresh(self::LOAD);
        $revision = new InvestigationRevision;
        $revision->forceFill(['order_id' => $order->id, 'item_id' => $item?->id, 'version' => $order->version,
            'operation' => self::ACTIONS[$action], 'actor_id' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason'], 'snapshot' => self::snapshot($order)])->save();
        $this->audit->record('investigation.'.self::ACTIONS[$action], $actor->id, 'investigation_order', $order->id,
            ['version' => $order->version, 'record_id' => $item?->id ?? $order->id]);

        return $order;
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        $order = InvestigationOrder::query()->visibleTo($actor)->lockForUpdate()->findOrFail($receipt->resource_id);
        if ($order->version !== (int) $receipt->resource_version) {
            throw new WriteConflict('idempotency_outdated', 'The original save completed, but this order changed again. Reload the latest saved order.');
        }

        return self::response($order);
    }

    public static function response(InvestigationOrder $order): JsonResponse
    {
        return (new InvestigationOrderResource($order->loadMissing(self::LOAD)))->response();
    }

    /** @return array<string, mixed> */
    public static function snapshot(InvestigationOrder $order): array
    {
        $order->loadMissing('items.schema');
        $statuses = $order->items->pluck('status');
        $status = $statuses->every(fn (string $status): bool => $status === 'cancelled') ? 'cancelled'
            : ($statuses->contains('processing') ? 'processing' : ($statuses->contains('collected') ? 'collected' : 'requested'));

        return $order->only(['id', 'patient_id', 'encounter_id', 'ordered_by', 'assignment_id', 'assignment_version', 'version', 'clinical_context', 'reason'])
            + ['requested_at' => $order->requested_at->toISOString(), 'last_changed_at' => $order->last_changed_at->toISOString(),
                'status' => $status, 'items' => $order->items->map(self::itemSnapshot(...))->all()];
    }

    /** @return array<string, mixed> */
    public static function itemSnapshot(InvestigationItem $item): array
    {
        $item->loadMissing('schema');
        $definition = $item->schema->schema_json;
        $data = $item->only(['id', 'order_id', 'encounter_id', 'test_id', 'schema_id', 'department', 'priority', 'status', 'version',
            'specimen_ref', 'collected_by', 'processing_by', 'cancelled_by', 'cancellation_reason']);
        foreach (['collected_at', 'processing_at', 'cancelled_at', 'last_event_at'] as $field) {
            $data[$field] = $item->{$field}?->toISOString();
        }

        return $data + ['test_snapshot' => ['code' => $definition['code'], 'name' => $definition['name'], 'specimen' => $definition['specimen'],
            'service' => $definition['service'], 'schema_version' => $item->schema->version, 'title' => $definition['title']]];
    }

    private function version(int $actual, mixed $expected): void
    {
        if ($actual !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This saved record changed. Reload it before starting another submission.');
        }
    }
}
