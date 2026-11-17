<?php

namespace Tests\Feature;

use App\Models\DoctorAssignment;
use App\Models\Encounter;
use App\Models\InvestigationItem;
use App\Models\InvestigationResult;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\InvestigationCatalog;
use App\Services\ProtectedFiles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvestigationResultApiTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;
    private User $author;
    private User $reviewer;
    private Encounter $visit;
    private InvestigationItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->fakeProtectedStorage();
        $this->grantRolePermissions('doctor', ['patients.view', 'encounters.view', 'investigations.view',
            'investigations.request', 'investigations.catalog.view', 'investigations.catalog.publish', 'investigations.results.view', 'encounters.progress']);
        $this->grantRolePermissions('diagnostics-and-radiologist', ['patients.view', 'encounters.view', 'encounters.view_any', 'investigations.view',
            'investigations.process', 'investigations.results.view', 'investigations.results.enter', 'investigations.results.verify',
            'investigations.results.release', 'investigations.results.amend', 'investigations.results.review', 'files.view_own', 'files.upload']);
        $this->doctor = $this->createStaffWithRole('doctor');
        $this->author = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->reviewer = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->visit = Encounter::factory()->create(['opened_at' => now('UTC')->subHours(2)]);
        $assignment = DoctorAssignment::factory()->create(['encounter_id' => $this->visit->id, 'doctor_id' => $this->doctor->id, 'assigned_at' => now('UTC')->subHour()]);
        $schema = app(InvestigationCatalog::class)->publish(['code' => 'synthetic-result-test', 'name' => 'Synthetic result test',
            'department' => 'radiology', 'specimen' => null, 'is_active' => true, 'title' => 'Synthetic result form',
            'service' => ['code' => 'synthetic-result-service', 'name' => 'Synthetic service'],
            'fields' => ['value' => ['type' => 'number', 'unit' => 'synthetic-unit', 'required' => true,
                'reference_ranges' => [['label' => 'Synthetic description', 'lower' => 10, 'upper' => 20]]],
                'flag' => ['type' => 'boolean'], 'report' => ['type' => 'text', 'max_length' => 20000]]],
            $this->doctor->id, 0, 'Synthetic approval reference', 'Synthetic approved publication');
        $this->login($this->doctor);
        $order = $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/investigations',
            ['patient_version' => 1, 'encounter_version' => 1, 'assignment_id' => $assignment->id, 'assignment_version' => 1,
                'reason' => 'Private investigation request', 'items' => [['test_id' => $schema->test_id, 'schema_id' => $schema->id, 'test_version' => 1, 'priority' => 'routine']]])
            ->assertCreated()->json('data');
        $this->login($this->author);
        $this->postCommand('/api/v1/investigations/orders/'.$order['id'].'/items/'.$order['items'][0]['id'].'/process',
            ['encounter_version' => 1, 'order_version' => 1, 'version' => 1, 'reason' => 'Synthetic processing'])->assertOk();
        $this->item = InvestigationItem::findOrFail($order['items'][0]['id']);
    }

    private function login(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user);
    }

    private function path(?array $result = null): string
    {
        return '/api/v1/investigations/items/'.$this->item->id.'/results'.($result === null ? '' : '/'.$result['id']);
    }

    private function entry(array $fields = ['value' => 0, 'flag' => false]): array
    {
        return ['encounter_version' => $this->visit->fresh()->version, 'item_version' => $this->item->fresh()->version,
            'reason' => 'Private result entry reason', 'fields' => $fields, 'comment' => 'Private result comment', 'attachment_ids' => []];
    }

    private function action(array $result): array
    {
        return ['encounter_version' => $this->visit->fresh()->version, 'item_version' => $this->item->fresh()->version,
            'version' => $result['version'], 'reason' => 'Private result workflow reason'];
    }

    private function draft(?array $data = null): array
    {
        return $this->postCommand($this->path(), $data ?? $this->entry())->assertCreated()->json('data');
    }

    private function submit(array $result): array
    {
        return $this->postCommand($this->path($result).'/submit', $this->action($result))->assertOk()->json('data');
    }

    private function release(?array $result = null): array
    {
        $result ??= $this->submit($this->draft());
        $this->login($this->reviewer);
        $result = $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD])
            ->assertOk()->json('data');

        return $this->postCommand($this->path($result).'/release', $this->action($result)
            + ['current_password' => self::STAFF_TEST_PASSWORD, 'order_version' => $this->item->order->fresh()->version])->assertOk()->json('data');
    }

    public function test_owned_drafts_remain_private_until_independent_verified_release_and_complete_the_request(): void
    {
        $result = $this->draft();
        $this->assertSame(0, $result['fields']['value']);
        $this->assertFalse($result['fields']['flag']);
        $this->assertSame($this->item->schema_id, $result['schema_id']);
        $this->login($this->reviewer);
        $this->getJson($this->path($result))->assertNotFound();
        $this->getJson('/api/v1/investigations/results')->assertJsonPath('meta.total', 0);
        $this->login($this->author);
        $result = $this->submit($result);
        $this->login($this->doctor);
        $this->getJson($this->path($result))->assertNotFound();
        $this->login($this->author);
        $released = $this->release($result);
        $this->assertSame($this->reviewer->id, $released['verified_by']);
        $this->assertSame($this->reviewer->id, $released['released_by']);
        $this->assertSame('completed', $this->item->fresh()->status);
        $this->assertDatabaseCount('investigation_result_revisions', 4);
        $this->login($this->doctor);
        $this->getJson($this->path($released))->assertOk()->assertJsonPath('data.status', 'released');
        $this->getJson('/api/v1/investigations/queue')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/investigations/orders/'.$this->item->order_id)->assertJsonPath('data.status', 'completed');
        $this->assertAuditExcludes(['Private result comment', 'Private result entry reason', 'Private result workflow reason', 'synthetic-unit']);
    }

    public function test_author_cannot_verify_and_only_the_independent_verifier_can_release_with_current_password(): void
    {
        $result = $this->submit($this->draft());
        $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertForbidden();
        $this->login($this->reviewer);
        $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => 'wrong'])->assertUnprocessable();
        $result = $this->postCommand($this->path($result).'/verify', $this->action($result) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        $third = $this->createStaffWithRole('diagnostics-and-radiologist');
        $this->login($third);
        $this->postCommand($this->path($result).'/release', $this->action($result) + ['order_version' => 2, 'current_password' => self::STAFF_TEST_PASSWORD])->assertForbidden();
        $this->login($this->reviewer);
        $this->postCommand($this->path($result).'/release', $this->action($result) + ['order_version' => 2, 'current_password' => 'wrong'])->assertUnprocessable();
        $this->assertSame('verified', InvestigationResult::sole()->status);
    }

    public function test_exact_confirmations_cannot_duplicate_results_and_stale_or_changed_commands_do_not_overwrite(): void
    {
        $key = ['Idempotency-Key' => 'synthetic-result-create-key'];
        $data = $this->entry();
        $result = $this->postCommand($this->path(), $data, $key)->assertCreated()->json('data');
        $this->postCommand($this->path(), $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand($this->path(), array_replace($data, ['comment' => 'Changed comment']), $key)->assertConflict();
        $this->postCommand($this->path(), $data)->assertConflict()->assertJsonPath('code', 'result_work_in_progress');
        $updated = $this->patchCommand($this->path($result), $this->entry(['value' => -999]) + ['version' => 1])->assertOk()->json('data');
        $this->assertSame(-999, $updated['fields']['value']);
        $this->patchCommand($this->path($result), $this->entry() + ['version' => 1])->assertConflict();
        $this->postCommand($this->path(), $data, $key)->assertConflict()->assertJsonPath('code', 'idempotency_outdated');
        $this->assertDatabaseCount('investigation_results', 1);
        $this->assertDatabaseCount('investigation_result_revisions', 2);
        $this->assertAuditEventRecorded('command.replayed', $this->author->id, 'investigation_result', $result['id']);
    }

    public function test_reasoned_correction_preserves_original_until_released_then_retains_both_versions(): void
    {
        $original = $this->release();
        $this->login($this->author);
        $draft = $this->postCommand($this->path($original).'/amend', $this->entry(['value' => 42]) + ['version' => $original['version']])->assertCreated()->json('data');
        $this->assertSame($original['id'], $draft['previous_result_id']);
        $this->assertSame(2, $draft['sequence']);
        $this->login($this->doctor);
        $this->getJson('/api/v1/investigations/results')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $original['id']);
        $this->login($this->author);
        $replacement = $this->release($this->submit($draft));
        $this->login($this->doctor);
        $this->getJson('/api/v1/investigations/results')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $replacement['id']);
        $this->getJson('/api/v1/investigations/results?current=0')->assertJsonPath('meta.total', 2);
        $this->getJson($this->path($original))->assertJsonPath('data.superseded_by', $replacement['id'])->assertJsonPath('data.fields.value', 0);
        $this->login($this->author);
        $this->postCommand($this->path($original).'/amend', $this->entry() + ['version' => $original['version']])->assertConflict();
        $this->getJson($this->path($replacement).'/history')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.3.operation', 'amended');
    }

    public function test_submitted_content_is_frozen_and_own_withdrawal_allows_a_new_working_version(): void
    {
        $result = $this->submit($this->draft());
        $this->patchCommand($this->path($result), $this->entry() + ['version' => $result['version']])->assertConflict();
        $this->postCommand($this->path($result).'/void', $this->action($result))->assertOk()->assertJsonPath('data.status', 'voided');
        $next = $this->draft();
        $this->assertSame(2, $next['sequence']);
        $this->assertDatabaseCount('investigation_results', 2);
    }

    public function test_incomplete_drafts_cannot_submit_and_reference_descriptions_are_not_validation_thresholds(): void
    {
        $draft = $this->draft($this->entry([]));
        $this->postCommand($this->path($draft).'/submit', $this->action($draft))->assertUnprocessable();
        $updated = $this->patchCommand($this->path($draft), $this->entry(['value' => -999, 'flag' => false]) + ['version' => 1])->assertOk()->json('data');
        $this->submit($updated);
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_entry_cannot_inject_identity_provenance_or_unsupported_values(array $changes): void
    {
        $this->postCommand($this->path(), array_replace($this->entry(), $changes))->assertUnprocessable();
        $this->assertDatabaseCount('investigation_results', 0);
    }

    public static function invalidEntries(): array
    {
        return [[['patient_id' => 1]], [['author_id' => 1]], [['schema_id' => 1]], [['released_at' => '2026-01-01']],
            [['status' => 'released']], [['fields' => ['unknown' => 3]]], [['fields' => ['flag' => 1]]],
            [['fields' => ['value' => '1e9999']]], [['fields' => ['value' => []]]], [['reason' => ' ']],
            [['attachment_ids' => [1, 1]]], [['attachment_ids' => 'invalid']], [['attachment_ids' => array_fill(0, 11, 1)]], [['comment' => str_repeat('x', 20001)]]];
    }

    public function test_patient_restriction_cross_item_ids_and_permission_revocation_hide_content_and_confirmation(): void
    {
        $data = $this->entry();
        $key = ['Idempotency-Key' => 'synthetic-result-scope-key'];
        $draft = $this->postCommand($this->path(), $data, $key)->assertCreated()->json('data');
        $other = InvestigationItem::factory()->create();
        $this->getJson('/api/v1/investigations/items/'.$other->id.'/results/'.$draft['id'])->assertNotFound();
        $this->item->order->patient->forceFill(['access_status' => 'restricted', 'version' => 2])->save();
        $this->getJson($this->path($draft))->assertNotFound();
        $this->getJson($this->path($draft).'/history')->assertNotFound();
        $this->getJson('/api/v1/investigations/results')->assertJsonPath('meta.total', 0);
        $this->postCommand($this->path(), $data, $key)->assertNotFound();
        $this->item->order->patient->forceFill(['access_status' => 'active', 'version' => 3])->save();
        Role::where('code', 'diagnostics-and-radiologist')->sole()->permissions()->detach(Permission::where('code', 'investigations.results.enter')->sole());
        $this->postCommand($this->path(), $data, $key)->assertForbidden();
    }

    public function test_failed_release_history_or_audit_rolls_back_result_completion_and_receipt(): void
    {
        $draft = $this->submit($this->draft());
        $this->login($this->reviewer);
        $verified = $this->postCommand($this->path($draft).'/verify', $this->action($draft) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        foreach (['investigation_result_revisions', 'investigation_revisions', 'audit_events'] as $table) {
            $triggered = $this->failNextWriteTo($table, 'insert');
            $this->assertTablesUnchanged(['investigation_results', 'investigation_result_revisions', 'investigation_items', 'investigation_orders',
                'investigation_revisions', 'idempotency_requests'], function () use ($verified): void {
                $this->postCommand($this->path($verified).'/release', $this->action($verified)
                    + ['order_version' => 2, 'current_password' => self::STAFF_TEST_PASSWORD])->assertStatus(500);
            });
            $this->assertTrue($triggered());
        }
        $this->assertSame('processing', $this->item->fresh()->status);
        $this->assertSame('verified', InvestigationResult::sole()->status);
    }

    public function test_private_attachments_require_ownership_scope_integrity_and_current_scan_policy(): void
    {
        $file = app(ProtectedFiles::class)->upload($this->author, UploadedFile::fake()->createWithContent('private-report.pdf', "%PDF-1.4\nSynthetic report\n%%EOF"));
        $data = array_replace($this->entry(), ['attachment_ids' => [$file->id]]);
        $draft = $this->draft($data);
        $this->getJson('/api/v1/files/'.$file->id)->assertNotFound();
        $this->getJson('/api/v1/files/'.$file->id.'/download')->assertNotFound();
        $this->getJson('/api/v1/files')->assertJsonPath('meta.total', 0);
        $this->get($this->path($draft).'/attachments/'.$file->id.'/download')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('not_scanned', $draft['attachments'][0]['scan_status']);
        $this->login($this->doctor);
        $this->getJson($this->path($draft).'/attachments/'.$file->id.'/download')->assertNotFound();
        $this->login($this->author);
        DB::table('files')->where('id', $file->id)->update(['scan_status' => 'blocked']);
        $this->getJson($this->path($draft).'/attachments/'.$file->id.'/download')->assertConflict();
        $this->postCommand($this->path($draft).'/submit', $this->action($draft))->assertConflict();
        DB::table('files')->where('id', $file->id)->update(['scan_status' => 'not_scanned']);
        $released = $this->release($this->submit($draft));
        $this->login($this->doctor);
        $this->get($this->path($released).'/attachments/'.$file->id.'/download')->assertOk();
        Storage::disk('protected')->put($file->storage_path, 'Corrupt bytes');
        $this->getJson($this->path($released).'/attachments/'.$file->id.'/download')->assertStatus(503);
        $this->assertAuditExcludes(['private-report.pdf', 'Synthetic report', $file->storage_path, $file->sha256]);
    }

    public function test_foreign_attachment_is_rejected_atomically_and_cross_result_download_is_hidden(): void
    {
        $file = app(ProtectedFiles::class)->upload($this->reviewer, UploadedFile::fake()->createWithContent('foreign.pdf', "%PDF-1.4\nSynthetic\n%%EOF"));
        $this->postCommand($this->path(), array_replace($this->entry(), ['attachment_ids' => [$file->id]]))->assertNotFound();
        $this->assertDatabaseCount('investigation_results', 0);
        $draft = $this->draft();
        $this->getJson($this->path($draft).'/attachments/'.$file->id.'/download')->assertNotFound();
    }

    public function test_guests_inactive_default_and_read_only_roles_cannot_write_or_read_private_history(): void
    {
        $draft = $this->draft();
        auth()->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/v1/investigations/results')->assertUnauthorized();
        $ordinary = $this->createStaffWithRole('nurse');
        $this->login($ordinary);
        $this->getJson('/api/v1/investigations/results')->assertForbidden();
        $this->postCommand($this->path(), $this->entry())->assertForbidden();
        $this->login($this->doctor);
        $this->getJson($this->path($draft).'/history')->assertForbidden();
        $this->postCommand($this->path(), $this->entry())->assertForbidden();
        $this->login($this->author);
        $this->author->forceFill(['is_active' => false])->save();
        $this->getJson($this->path($draft))->assertUnauthorized();
        $this->assertDatabaseCount('investigation_results', 1);
        $this->assertDatabaseCount('permissions', 88);
        $this->assertDatabaseCount('role_permissions', 21 + 8 + 13);
    }

    public function test_verify_confirmation_rechecks_password_permission_and_stored_version(): void
    {
        $draft = $this->submit($this->draft());
        $this->login($this->reviewer);
        $data = $this->action($draft) + ['current_password' => self::STAFF_TEST_PASSWORD];
        $key = ['Idempotency-Key' => 'synthetic-result-verify-key'];
        $this->postCommand($this->path($draft).'/verify', $data, $key)->assertOk();
        $this->postCommand($this->path($draft).'/verify', $data, $key)->assertOk()->assertHeader('Idempotency-Replayed', 'true');
        $this->postCommand($this->path($draft).'/verify', array_replace($data, ['current_password' => 'wrong']), $key)->assertUnprocessable();
        Role::where('code', 'diagnostics-and-radiologist')->sole()->permissions()->detach(Permission::where('code', 'investigations.results.verify')->sole());
        $this->postCommand($this->path($draft).'/verify', $data, $key)->assertNotFound();
        $this->assertDatabaseCount('investigation_result_revisions', 3);
    }

    public function test_stale_visit_item_order_versions_and_backward_clock_roll_back_commands(): void
    {
        $this->postCommand($this->path(), array_replace($this->entry(), ['item_version' => 1]))->assertConflict();
        $this->postCommand($this->path(), array_replace($this->entry(), ['encounter_version' => 2]))->assertConflict();
        $this->travelTo($this->item->last_event_at->subSecond());
        $this->postCommand($this->path(), $this->entry())->assertConflict()->assertJsonPath('code', 'investigation_clock_conflict');
        $this->travelBack();
        $draft = $this->submit($this->draft());
        $this->login($this->reviewer);
        $verified = $this->postCommand($this->path($draft).'/verify', $this->action($draft) + ['current_password' => self::STAFF_TEST_PASSWORD])->assertOk()->json('data');
        $this->postCommand($this->path($verified).'/release', $this->action($verified) + ['order_version' => 1, 'current_password' => self::STAFF_TEST_PASSWORD])->assertConflict();
        $this->assertSame('verified', InvestigationResult::sole()->status);
        $this->assertSame('processing', $this->item->fresh()->status);
    }

    public function test_pending_correction_blocks_closure_and_closed_visits_reject_new_amendments(): void
    {
        $released = $this->release();
        $this->login($this->doctor);
        $assignment = $this->visit->currentAssignment;
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/accept', ['version' => 1, 'assignment_id' => $assignment->id,
            'assignment_version' => 1, 'reason' => 'Synthetic visit acceptance'])->assertOk();
        $this->login($this->author);
        $draft = $this->postCommand($this->path($released).'/amend', $this->entry() + ['version' => $released['version']])->assertCreated()->json('data');
        $complete = ['version' => 2, 'assignment_id' => $assignment->id, 'assignment_version' => 2, 'reason' => 'Synthetic visit completion'];
        $this->login($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/complete', $complete)->assertConflict()->assertJsonPath('code', 'encounter_pending_investigations');
        $this->login($this->author);
        $this->postCommand($this->path($draft).'/void', $this->action($draft))->assertOk();
        $this->login($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/complete', $complete)->assertOk();
        $this->getJson($this->path($released))->assertOk();
        $this->login($this->author);
        $this->postCommand($this->path($released).'/amend', $this->entry() + ['version' => $released['version']])->assertConflict()->assertJsonPath('code', 'investigation_visit_closed');
    }

    public function test_removed_attachment_keeps_permanent_scope_and_failed_update_restores_links(): void
    {
        $file = app(ProtectedFiles::class)->upload($this->author, UploadedFile::fake()->createWithContent('retained.pdf', "%PDF-1.4\nSynthetic\n%%EOF"));
        $draft = $this->draft(array_replace($this->entry(), ['attachment_ids' => [$file->id]]));
        $triggered = $this->failNextWriteTo('investigation_result_revisions', 'insert');
        $this->assertTablesUnchanged(['investigation_results', 'investigation_result_attachments', 'investigation_result_file_bindings', 'idempotency_requests'], function () use ($draft): void {
            $this->patchCommand($this->path($draft), $this->entry() + ['version' => 1])->assertStatus(500);
        });
        $this->assertTrue($triggered());
        $this->patchCommand($this->path($draft), $this->entry() + ['version' => 1])->assertOk()->assertJsonCount(0, 'data.attachments');
        $this->getJson('/api/v1/files/'.$file->id.'/download')->assertNotFound();
        $this->getJson($this->path($draft).'/attachments/'.$file->id.'/download')->assertNotFound();
        $this->assertDatabaseCount('investigation_result_file_bindings', 1);
    }

    public function test_result_lists_bound_pagination_and_filters_do_not_leak_other_authors_drafts(): void
    {
        $draft = $this->draft();
        $this->getJson('/api/v1/investigations/results?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/investigations/results?status=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/investigations/results?author_id='.$this->reviewer->id)->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/investigations/results?patient_id='.$this->visit->patient_id.'&status=draft')->assertJsonPath('meta.total', 1);
        $this->getJson($this->path().'?per_page=1')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $draft['id']);
        $this->login($this->reviewer);
        $this->getJson($this->path().'?current=0')->assertJsonPath('meta.total', 0);
    }

    public function test_result_history_prevents_backdated_new_drafts_after_withdrawal(): void
    {
        $this->travel(10)->minutes();
        $draft = $this->draft();
        $this->postCommand($this->path($draft).'/void', $this->action($draft))->assertOk();
        $this->travelBack();
        $this->postCommand($this->path(), $this->entry())->assertConflict()->assertJsonPath('code', 'investigation_clock_conflict');
        $this->assertDatabaseCount('investigation_results', 1);
    }

    public function test_released_results_prevent_backdated_visit_closure(): void
    {
        $this->login($this->doctor);
        $assignment = $this->visit->currentAssignment;
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/accept', ['version' => 1, 'assignment_id' => $assignment->id,
            'assignment_version' => 1, 'reason' => 'Synthetic visit acceptance'])->assertOk();
        $this->login($this->author);
        $this->travel(10)->minutes();
        $this->release();
        $this->travelBack();
        $this->login($this->doctor);
        $this->postCommand('/api/v1/encounters/'.$this->visit->id.'/complete', ['version' => 2, 'assignment_id' => $assignment->id,
            'assignment_version' => 2, 'reason' => 'Synthetic visit completion'])->assertConflict()->assertJsonPath('code', 'encounter_clock_conflict');
        $this->assertSame('in_progress', $this->visit->fresh()->status);
    }

    public function test_unicode_comment_and_fields_round_trip_without_truncation(): void
    {
        $text = str_repeat('🩺', 20000);
        $draft = $this->draft(array_replace($this->entry(['value' => 0, 'report' => $text]), ['comment' => $text]));
        $this->assertSame($text, $draft['comment']);
        $this->assertSame($text, $draft['fields']['report']);
        $this->assertSame($text, InvestigationResult::sole()->revisions->sole()->snapshot['comment']);
    }
}
