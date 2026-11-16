<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListInvestigationTestsRequest;
use App\Http\Resources\InvestigationSchemaResource;
use App\Http\Resources\InvestigationTestResource;
use App\Models\InvestigationTest;
use App\Services\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvestigationCatalogController extends Controller
{
    public function options(Request $request, AuditTrail $audit): JsonResponse
    {
        $audit->record('investigation.listed', $request->user('web')->id, 'investigation_test');

        return response()->json(['data' => ['departments' => InvestigationTest::DEPARTMENTS,
            'field_types' => ['text', 'number', 'integer', 'boolean', 'date', 'select'],
            'reference_ranges_are_descriptive' => true, 'prices_are_configured' => false]]);
    }

    public function index(ListInvestigationTestsRequest $request, AuditTrail $audit): AnonymousResourceCollection
    {
        $query = $this->query();
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        if ($request->has('department')) {
            $query->where('department', $request->string('department')->toString());
        }
        if ($request->has('q')) {
            $term = $request->string('q')->toString();
            $query->where(fn (Builder $tests): Builder => $tests->whereLike('name', '%'.$term.'%')->orWhere('code', $term));
        }
        $audit->record('investigation.listed', $request->user('web')->id, 'investigation_test');

        return InvestigationTestResource::collection($query->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function show(Request $request, int $investigationTest, AuditTrail $audit): InvestigationTestResource
    {
        $test = $this->query()->findOrFail($investigationTest);
        $audit->record('investigation.viewed', $request->user('web')->id, 'investigation_test', $test->id);

        return new InvestigationTestResource($test);
    }

    public function schemas(ListInvestigationTestsRequest $request, int $investigationTest, AuditTrail $audit): AnonymousResourceCollection
    {
        $test = $this->query()->findOrFail($investigationTest);
        $audit->record('investigation.schemas_viewed', $request->user('web')->id, 'investigation_test', $test->id);

        return InvestigationSchemaResource::collection($test->schemas()->reorder()->orderByDesc('version')
            ->paginate($request->integer('per_page', config('api.pagination.per_page')))->appends($request->validated()));
    }

    public function schema(Request $request, int $investigationTest, int $schema, AuditTrail $audit): InvestigationSchemaResource
    {
        $test = $this->query()->findOrFail($investigationTest);
        $version = $test->schemas()->findOrFail($schema);
        $audit->record('investigation.schemas_viewed', $request->user('web')->id, 'investigation_test', $test->id);

        return new InvestigationSchemaResource($version);
    }

    private function query(): Builder
    {
        return InvestigationTest::query()->with(['service', 'latestSchema'])
            ->whereHas('schemas', fn (Builder $schemas): Builder => $schemas->whereColumn('investigation_schemas.version', 'investigation_tests.version'));
    }
}
