<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExportRequest;
use App\Services\DocumentExports;
use App\Services\ExportRendering;
use App\Services\ExportReports;
use App\Services\IdempotentCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json(['data'=>['kinds'=>['report','invoice','receipt','prescription','clinical','result'],
            'report_keys'=>ExportReports::KINDS,'report_formats'=>['csv','html'],'record_formats'=>['html'],
            'expires_after_hours'=>24,'maximum_source_records'=>ExportReports::MAX_RECORDS,
            'maximum_flattened_rows'=>ExportRendering::MAX_ROWS,'maximum_bytes'=>ExportRendering::MAX_BYTES,
            'print_method'=>'browser print or save as PDF','official_templates_approved'=>false]]);
    }

    public function store(Request $request,DocumentExports $exports,IdempotentCommands $commands): JsonResponse
    {
        return $commands->run($request,fn (): JsonResponse=>$exports->create($request->user('web'),app(ExportRequest::class)->validated()));
    }

    public function show(Request $request,DocumentExports $exports): JsonResponse
    {
        return $exports->show($request->user('web'),(int)$request->route('export'));
    }

    public function download(Request $request,DocumentExports $exports): Response
    {
        return $exports->download($request->user('web'),(int)$request->route('export'),$request->route()->getName()==='api.v1.exports.print');
    }
}
