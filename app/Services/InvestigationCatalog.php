<?php

namespace App\Services;

use App\Models\InvestigationSchema;
use App\Models\InvestigationTest;
use App\Models\Permission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class InvestigationCatalog
{
    public function __construct(private WriteTransactions $transactions, private InvestigationDefinitions $definitions, private AuditTrail $audit) {}

    public function publish(mixed $input, int $publisher, int $expectedVersion, string $approvalReference, string $reason): InvestigationSchema
    {
        $data = $this->definitions->validate($input);
        $approvalReference = trim($approvalReference);
        $reason = trim($reason);
        Validator::make(['expected_version' => $expectedVersion, 'approval_reference' => $approvalReference, 'reason' => $reason], [
            'expected_version' => ['required', 'integer', 'min:0', 'max:4294967293'],
            'approval_reference' => ['required', 'string', 'min:5', 'max:2000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ])->validate();
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return $this->transactions->run(function () use ($data, $publisher, $expectedVersion, $approvalReference, $reason, $hash): InvestigationSchema {
            Permission::query()->where('code', 'staff.manage')->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($publisher);
            if (! $actor->is_active || ! $actor->hasPermission('investigations.catalog.publish')) {
                throw new RuntimeException('Publisher is not authorized.');
            }
            $test = InvestigationTest::query()->where('code', $data['code'])->lockForUpdate()->first();
            $latest = $test?->schemas()->reorder()->orderByDesc('version')->first();
            $currentVersion = $test?->version ?? 0;
            if ($expectedVersion !== $currentVersion) {
                if ($latest !== null && $latest->version === $expectedVersion + 1 && $currentVersion === $latest->version
                    && $latest->content_hash === $hash && $latest->published_by === $publisher
                    && $latest->approval_reference === $approvalReference && $latest->reason === $reason) {
                    return $latest;
                }
                throw new RuntimeException('Catalog version changed. Review the saved definition before publishing.');
            }
            $service = Service::query()->where('code', $data['service']['code'])->lockForUpdate()->first();
            if ($service === null) {
                $service = new Service;
                $service->forceFill(['code' => $data['service']['code'], 'name' => $data['service']['name'],
                    'category' => $data['department'], 'is_active' => true, 'created_by' => $actor->id])->save();
            }
            if ($service->name !== $data['service']['name'] || $service->category !== $data['department'] || ! $service->is_active) {
                throw new RuntimeException('The supplied service identity conflicts with the saved service.');
            }
            if ($test !== null && ($test->department !== $data['department'] || $test->service_id !== $service->id)) {
                throw new RuntimeException('Test department and service identity stay fixed.');
            }
            $test ??= new InvestigationTest;
            if (! $test->exists) {
                $test->forceFill(['code' => $data['code'], 'department' => $data['department'], 'service_id' => $service->id, 'created_by' => $actor->id]);
            }
            $test->forceFill(['name' => $data['name'], 'specimen' => $data['specimen'], 'is_active' => $data['is_active'], 'version' => $expectedVersion + 1])->save();
            $schema = new InvestigationSchema;
            $schema->forceFill(['test_id' => $test->id, 'version' => $test->version, 'schema_json' => $data, 'content_hash' => $hash,
                'published_by' => $actor->id, 'published_at' => now('UTC'), 'approval_reference' => $approvalReference, 'reason' => $reason])->save();
            $this->audit->record('investigation.published', $actor->id, 'investigation_test', $test->id,
                ['version' => $schema->version, 'record_id' => $schema->id], channel: 'console');

            return $schema;
        });
    }
}
