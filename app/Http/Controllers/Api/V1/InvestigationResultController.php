<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InvestigationResultCommandRequest;
use App\Http\Requests\Api\V1\ListInvestigationResultsRequest;
use App\Http\Resources\InvestigationResultResource;
use App\Http\Resources\InvestigationResultRevisionResource;
use App\Models\InvestigationItem;
use App\Models\InvestigationResult;
use App\Services\AuditTrail;
use App\Services\IdempotentCommands;
use App\Services\InvestigationResults;
use App\Services\ProtectedFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InvestigationResultController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data' => ['timezone' => 'Africa/Lagos', 'statuses' => InvestigationResult::STATUSES,
            'independent_verifier' => true, 'release_by_verifier' => true, 'closed_visits_read_only' => true,
            'allowed_attachment_scan_states' => ['not_scanned', 'clean'], 'max_attachments' => 10]]);
    }

    public function index(ListInvestigationResultsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = InvestigationResult::query()->visibleTo($request->user('web'))->with(InvestigationResults::LOAD);
        if ($request->route('investigationItem') !== null) {
            $item = InvestigationItem::query()->visibleTo($request->user('web'))->findOrFail($request->route('investigationItem'));
            $query->where('item_id', $item->id);
        }
        foreach (['encounter_id', 'item_id', 'author_id', 'status'] as $field) {
            if ($request->has($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->has('patient_id')) {
            $query->whereHas('item.order', fn (Builder $orders): Builder => $orders->where('patient_id', $request->integer('patient_id')));
        }
        if ($request->boolean('current', true)) {
            $query->whereDoesntHave('replacements', fn (Builder $results): Builder => $results->where('status', 'released'));
        }
        $audit->record('investigation.results_listed', $request->user('web')->id, 'investigation_result');

        return InvestigationResultResource::collection($query->orderByDesc('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    private function saved(Request $request): InvestigationResult
    {
        return InvestigationResult::query()->visibleTo($request->user('web'))->where('item_id', $request->route('investigationItem'))
            ->with(InvestigationResults::LOAD)->findOrFail($request->route('investigationResult'));
    }

    public function show(Request $request, AuditTrail $audit): InvestigationResultResource
    {
        $result = $this->saved($request);
        $audit->record('investigation.result_viewed', $request->user('web')->id, 'investigation_result', $result->id);

        return new InvestigationResultResource($result);
    }

    public function history(ListInvestigationResultsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $result = $this->saved($request);
        $audit->record('investigation.result_history_viewed', $request->user('web')->id, 'investigation_result', $result->id);

        return InvestigationResultRevisionResource::collection($result->revisions()->reorder()->orderByDesc('version')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function write(Request $request, IdempotentCommands $commands, InvestigationResults $flow): JsonResponse
    {
        return $commands->run($request, function () use ($request, $flow): JsonResponse {
            $result = $flow->execute($request, $request->user('web'), app(InvestigationResultCommandRequest::class)->validated());

            return InvestigationResults::response($result)->setStatusCode(in_array(InvestigationResults::action($request), ['store', 'amend'], true) ? 201 : 200);
        });
    }

    public function download(Request $request, InvestigationResults $flow, ProtectedFiles $files, AuditTrail $audit): StreamedResponse
    {
        $result = $this->saved($request);
        $link = $result->attachments()->where('file_id', $request->route('file'))->firstOrFail();
        $file = $flow->attachmentFile($link);
        $stream = $files->openForRecord($file);
        try {
            $audit->record('investigation.result_attachment_download_authorized', $request->user('web')->id, 'investigation_result', $result->id);
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $link->original_name, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $link->byte_size,
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
