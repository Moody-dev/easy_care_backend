<?php

namespace App\Services;

use App\Exceptions\WriteConflict;
use App\Http\Resources\InvestigationResultResource;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationOrder;
use App\Models\InvestigationResult;
use App\Models\InvestigationResultAttachment;
use App\Models\InvestigationResultRevision;
use App\Models\InvestigationRevision;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use stdClass;

class InvestigationResults
{
    public const ACTIONS = ['store' => 'created', 'update' => 'updated', 'submit' => 'submitted', 'verify' => 'verified',
        'release' => 'released', 'amend' => 'amended', 'void' => 'voided'];
    public const LOAD = ['schema', 'item.order.patient', 'attachments'];
    public const WORKING = ['draft', 'submitted', 'verified'];

    public function __construct(private AuditTrail $audit, private InvestigationResultPayloads $payloads, private ProtectedFiles $files) {}

    public static function isCommand(string $route): bool
    {
        return str_starts_with($route, 'api.v1.investigations.results.') && array_key_exists(substr($route, strrpos($route, '.') + 1), self::ACTIONS);
    }

    public static function action(Request $request): string
    {
        return substr($request->route()->getName(), strrpos($request->route()->getName(), '.') + 1);
    }

    public function authorize(Request $request, User $actor): void
    {
        foreach (['patients.view', 'encounters.view', 'investigations.view', 'investigations.results.view'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        abort_unless($actor->is_active && $actor->roles()->where('code', 'diagnostics-and-radiologist')->exists(), 403);
        $itemId = $request->route('investigationItem');
        $visitId = InvestigationItem::query()->whereKey($itemId)->value('encounter_id');
        $patientId = Encounter::query()->whereKey($visitId)->value('patient_id');
        Patient::query()->visibleTo($actor)->lockForUpdate()->findOrFail($patientId);
        $visit = Encounter::query()->visibleTo($actor)->lockForUpdate()->findOrFail($visitId);
        $orderId = InvestigationItem::query()->whereKey($itemId)->value('order_id');
        $order = InvestigationOrder::query()->visibleTo($actor)->lockForUpdate()->findOrFail($orderId);
        $item = $order->items()->lockForUpdate()->findOrFail($itemId);
        $result = $request->route('investigationResult') === null ? null
            : InvestigationResult::query()->visibleTo($actor)->where('item_id', $item->id)->lockForUpdate()->findOrFail($request->route('investigationResult'));
        $action = self::action($request);
        if (in_array($action, ['verify', 'release'], true)) {
            Gate::forUser($actor)->authorize('investigations.results.'.$action);
            abort_unless($result !== null && $result->author_id !== $actor->id, 403);
            if ($action === 'release') {
                abort_unless($result->verified_by === $actor->id, 403);
            }
            $password = $request->input('current_password');
            if (! is_string($password) || strlen($password) > 1024 || ! Hash::check($password, $actor->password)) {
                throw ValidationException::withMessages(['current_password' => ['Your current password is incorrect.']]);
            }
        } else {
            Gate::forUser($actor)->authorize('investigations.results.enter');
            if ($action === 'amend' || $result?->previous_result_id !== null) {
                Gate::forUser($actor)->authorize('investigations.results.amend');
            }
            if ($result !== null && $action !== 'amend') {
                abort_unless($result->author_id === $actor->id, 403);
            }
        }
        $request->attributes->set('result_visit', $visit);
        $request->attributes->set('result_order', $order);
        $request->attributes->set('result_item', $item);
        $request->attributes->set('saved_result', $result);
    }

    /** @param array<string, mixed> $data */
    public function execute(Request $request, User $actor, array $data): InvestigationResult
    {
        $visit = $request->attributes->get('result_visit');
        $order = $request->attributes->get('result_order');
        $item = $request->attributes->get('result_item');
        $original = $request->attributes->get('saved_result');
        $action = self::action($request);
        $this->version($visit->version, $data['encounter_version']);
        $this->version($item->version, $data['item_version']);
        if (! in_array($visit->status, ['open', 'in_progress'], true)) {
            throw new WriteConflict('investigation_visit_closed', 'Closed visits are read-only.');
        }
        $time = now('UTC');
        $lastResultEvent = $item->results()->max('last_changed_at');
        if ($time->lessThan($item->last_event_at) || $time->lessThan($order->last_changed_at)
            || $original !== null && $time->lessThan($original->last_changed_at)
            || $lastResultEvent !== null && $time->lessThan(\Carbon\CarbonImmutable::parse($lastResultEvent, 'UTC'))) {
            throw new WriteConflict('investigation_clock_conflict', 'The server time precedes a saved investigation event.');
        }
        if (! in_array($item->status, ['processing', 'completed'], true)) {
            throw new WriteConflict('result_item_not_processing', 'Start processing this requested item before entering results.');
        }
        if ($original !== null) {
            $this->version($original->version, $data['version']);
        }
        $result = $original;
        if (in_array($action, ['store', 'amend'], true)) {
            $latest = $item->results()->where('status', 'released')->reorder()->orderByDesc('sequence')->lockForUpdate()->first();
            if ($action === 'amend' && ($original->status !== 'released' || $latest?->id !== $original->id)
                || $action === 'store' && $latest !== null) {
                throw new WriteConflict('result_amendment_conflict', 'Choose the latest released result for a reasoned correction.');
            }
            if ($item->results()->whereIn('status', self::WORKING)->lockForUpdate()->exists()) {
                throw new WriteConflict('result_work_in_progress', 'This test already has a working result version.');
            }
            $result = new InvestigationResult;
            $result->forceFill(['item_id' => $item->id, 'test_id' => $item->test_id, 'schema_id' => $item->schema_id, 'encounter_id' => $item->encounter_id,
                'previous_result_id' => $original?->id, 'sequence' => ((int) $item->results()->max('sequence')) + 1,
                'version' => 1, 'status' => 'draft', 'author_id' => $actor->id, 'fields' => (object) $this->payloads->validate($item->schema, $data['fields'], false),
                'comment' => $data['comment'], 'reason' => $data['reason'], 'last_changed_at' => $time,
                'created_at' => $time, 'updated_at' => $time])->save();
            $this->replaceAttachments($result, $data['attachment_ids'], $actor);
        } else {
            $allowed = match ($action) {
                'update', 'submit' => $result->status === 'draft',
                'verify' => $result->status === 'submitted',
                'release' => $result->status === 'verified',
                'void' => in_array($result->status, self::WORKING, true),
            };
            if (! $allowed) {
                throw new WriteConflict('result_transition_conflict', 'This saved result cannot make that transition.');
            }
            if ($action === 'update') {
                $result->forceFill(['fields' => (object) $this->payloads->validate($item->schema, $data['fields'], false),
                    'comment' => $data['comment']]);
                $this->replaceAttachments($result, $data['attachment_ids'], $actor);
            } elseif (in_array($action, ['submit', 'verify', 'release'], true)) {
                $this->payloads->validate($item->schema, $result->fields, true);
                $this->checkAttachments($result);
            }
            $changes = match ($action) {
                'update' => [],
                'submit' => ['status' => 'submitted', 'submitted_at' => $time],
                'verify' => ['status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => $time],
                'release' => ['status' => 'released', 'released_by' => $actor->id, 'released_at' => $time],
                'void' => ['status' => 'voided', 'voided_at' => $time],
            };
            $result->forceFill($changes + ['version' => $result->version + 1, 'last_changed_at' => $time])->save();
            if ($action === 'release') {
                $this->version($order->version, $data['order_version']);
                if ($item->status === 'processing') {
                    $item->forceFill(['status' => 'completed', 'version' => $item->version + 1, 'last_event_at' => $time])->save();
                    $order->forceFill(['version' => $order->version + 1, 'last_changed_at' => $time])->save();
                    $revision = new InvestigationRevision;
                    $revision->forceFill(['order_id' => $order->id, 'item_id' => $item->id, 'version' => $order->version,
                        'operation' => 'completed', 'actor_id' => $actor->id, 'recorded_at' => $time,
                        'reason' => $data['reason'], 'snapshot' => InvestigationRequests::snapshot($order->fresh(InvestigationRequests::LOAD))])->save();
                }
            }
        }
        $result = $result->fresh(self::LOAD);
        $revision = new InvestigationResultRevision;
        $revision->forceFill(['result_id' => $result->id, 'version' => $result->version, 'operation' => self::ACTIONS[$action],
            'actor_id' => $actor->id, 'recorded_at' => $time, 'reason' => $data['reason'], 'snapshot' => self::snapshot($result)])->save();
        $this->audit->record('investigation.result_'.self::ACTIONS[$action], $actor->id, 'investigation_result', $result->id,
            ['version' => $result->version, 'record_id' => $item->id]);

        return $result;
    }

    /** @param list<int> $ids */
    private function replaceAttachments(InvestigationResult $result, array $ids, User $actor): void
    {
        $existing = $result->attachments()->pluck('file_id')->all();
        foreach (collect($ids)->map(fn (mixed $id): int => (int) $id)->sort()->all() as $id) {
            $file = DB::table('files')->where('id', $id)->where('uploaded_by', $actor->id)->lockForUpdate()->first();
            abort_if($file === null, 404);
            Gate::forUser($actor)->authorize('files.view_own');
            $stream = $this->files->openForRecord($file);
            fclose($stream);
            $binding = DB::table('investigation_result_file_bindings')->where('file_id', $id)->lockForUpdate()->first();
            if ($binding !== null && (int) $binding->item_id !== $result->item_id) {
                throw new WriteConflict('result_attachment_already_bound', 'This file already belongs to another requested test.');
            }
            if ($binding === null) {
                DB::table('investigation_result_file_bindings')->insert(['file_id' => $id, 'item_id' => $result->item_id]);
            }
            if (! in_array($id, $existing, true)) {
                $link = new InvestigationResultAttachment;
                $link->forceFill(['result_id' => $result->id, 'file_id' => $id, 'original_name' => $file->original_name,
                    'mime_type' => $file->mime_type, 'byte_size' => $file->byte_size, 'sha256' => $file->sha256])->save();
            }
        }
        $result->attachments()->whereNotIn('file_id', $ids)->delete();
        $result->unsetRelation('attachments');
    }

    public function checkAttachments(InvestigationResult $result): void
    {
        foreach ($result->attachments as $link) {
            $file = $this->attachmentFile($link, true);
            $stream = $this->files->openForRecord($file);
            fclose($stream);
        }
    }

    public function attachmentFile(InvestigationResultAttachment $link, bool $lock = false): stdClass
    {
        $query = DB::table('files')->where('id', $link->file_id);
        $file = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($file !== null && hash_equals($link->sha256, $file->sha256)
            && (int) $file->byte_size === $link->byte_size && $file->mime_type === $link->mime_type && $file->original_name === $link->original_name, 503);

        return $file;
    }

    public function replay(Request $request, User $actor, stdClass $receipt): JsonResponse
    {
        $result = InvestigationResult::query()->visibleTo($actor)->lockForUpdate()->findOrFail($receipt->resource_id);
        if ($result->version !== (int) $receipt->resource_version
            || $result->replacements()->where('status', 'released')->exists()) {
            throw new WriteConflict('idempotency_outdated', 'The saved result changed. Reload its latest version.');
        }

        return self::response($result);
    }

    public static function response(InvestigationResult $result): JsonResponse
    {
        return (new InvestigationResultResource($result->loadMissing(self::LOAD)))->response();
    }

    /** @return array<string, mixed> */
    public static function snapshot(InvestigationResult $result): array
    {
        $result->loadMissing(self::LOAD);
        $files = DB::table('files')->whereIn('id', $result->attachments->pluck('file_id'))->pluck('scan_status', 'id');

        return $result->only(['id', 'item_id', 'test_id', 'schema_id', 'encounter_id', 'previous_result_id', 'sequence', 'version',
            'status', 'author_id', 'verified_by', 'released_by', 'comment', 'reason'])
            + ['fields' => (object) $result->fields, 'patient_id' => $result->item->order->patient_id, 'order_id' => $result->item->order_id,
                'submitted_at' => $result->submitted_at?->toISOString(), 'verified_at' => $result->verified_at?->toISOString(),
                'released_at' => $result->released_at?->toISOString(), 'voided_at' => $result->voided_at?->toISOString(),
                'created_at' => $result->created_at->toISOString(), 'last_changed_at' => $result->last_changed_at->toISOString(),
                'attachments' => $result->attachments->map(fn (InvestigationResultAttachment $link): array =>
                    $link->only(['file_id', 'original_name', 'mime_type', 'byte_size', 'sha256']) + ['scan_status' => $files[$link->file_id] ?? 'unavailable'])->all()];
    }

    private function version(int $actual, mixed $expected): void
    {
        if ($actual !== (int) $expected) {
            throw new WriteConflict('version_conflict', 'This saved record changed. Reload it before another submission.');
        }
    }
}
