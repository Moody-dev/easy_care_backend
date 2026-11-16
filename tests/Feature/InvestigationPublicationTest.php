<?php

namespace Tests\Feature;

use App\Models\InvestigationSchema;
use App\Models\Permission;
use App\Models\Role;
use App\Services\InvestigationCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class InvestigationPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return ['code' => 'synthetic-panel', 'name' => 'Synthetic panel', 'department' => 'laboratory', 'specimen' => 'Synthetic specimen',
            'is_active' => true, 'service' => ['code' => 'synthetic-service', 'name' => 'Synthetic service'], 'title' => 'Synthetic approved form',
            'fields' => ['reading' => ['type' => 'number', 'label' => 'Synthetic reading', 'unit' => 'test-unit', 'required' => true,
                'reference_ranges' => [['label' => 'Synthetic reference only', 'population' => 'Synthetic population description', 'lower' => 0, 'upper' => 12.5]]],
                'observation' => ['type' => 'text', 'max_length' => 20000], 'confirmed' => ['type' => 'boolean']]];
    }

    private function publisher(): int
    {
        $this->grantRolePermissions('diagnostics-and-radiologist', ['investigations.catalog.publish']);

        return $this->createStaffWithRole('diagnostics-and-radiologist')->id;
    }

    public function test_publication_preserves_old_units_ranges_names_and_provenance(): void
    {
        $actor = $this->publisher();
        $catalog = app(InvestigationCatalog::class);
        $input = $this->definition();
        $first = $catalog->publish($input, $actor, 0, 'Synthetic approval v1', 'Initial approved publication');
        $input['name'] = 'Revised synthetic panel';
        $input['specimen'] = 'Revised synthetic specimen';
        $input['fields']['reading']['unit'] = 'revised-unit';
        $input['fields']['reading']['reference_ranges'][0]['upper'] = 20;
        $second = $catalog->publish($input, $actor, 1, 'Synthetic approval v2', 'Approved definition revision');
        $this->assertSame([1, 2], InvestigationSchema::orderBy('version')->pluck('version')->all());
        $this->assertEquals($this->definition()['fields'], $first->fresh()->schema_json['fields']);
        $this->assertSame('Synthetic panel', $first->fresh()->schema_json['name']);
        $this->assertSame('revised-unit', $second->schema_json['fields']['reading']['unit']);
        $this->assertSame($actor, $second->publishedBy->id);
        $this->assertSame($first->test_id, $second->test_id);
        $this->assertSame($first->test->service_id, $second->test->service_id);
        $this->assertDatabaseCount('services', 1);
        $this->assertDatabaseCount('patients', 0);
        $this->assertAuditEventRecorded('investigation.published', $actor, 'investigation_test', $first->test_id, count: 2);
        $this->assertSame(['reading', 'observation', 'confirmed'], $first->fresh()->schema_json['field_order']);
        $this->assertAuditExcludes(['Synthetic specimen', 'Synthetic population description', 'Synthetic approval v1']);
    }

    public function test_exact_console_retry_confirms_one_version_and_does_not_append_another_audit(): void
    {
        $actor = $this->publisher();
        $input = $this->definition();
        $catalog = app(InvestigationCatalog::class);
        $first = $catalog->publish($input, $actor, 0, 'Synthetic approval', 'Approved initial definition');
        $input = array_reverse($input, true);
        $retry = $catalog->publish($input, $actor, 0, 'Synthetic approval', 'Approved initial definition');
        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('investigation_tests', 1);
        $this->assertDatabaseCount('investigation_schemas', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'investigation.published')->count());
    }

    public function test_retirement_and_restoration_append_versions_without_erasing_approved_forms(): void
    {
        $actor = $this->publisher();
        $catalog = app(InvestigationCatalog::class);
        $input = $this->definition();
        $first = $catalog->publish($input, $actor, 0, 'Synthetic approval', 'Initial publication');
        $input['is_active'] = false;
        $catalog->publish($input, $actor, 1, 'Synthetic retirement approval', 'Retire obsolete catalog entry');
        $this->assertFalse($first->test->is_active);
        $input['is_active'] = true;
        $catalog->publish($input, $actor, 2, 'Synthetic restoration approval', 'Restore approved catalog entry');
        $this->assertTrue($first->test->fresh()->is_active);
        $this->assertDatabaseCount('investigation_schemas', 3);
        $this->assertTrue($first->fresh()->schema_json['is_active']);
    }

    public function test_publication_is_opt_in_and_requires_active_staff(): void
    {
        $actor = $this->createStaffWithRole('admin');
        $catalog = app(InvestigationCatalog::class);
        foreach ([false, true] as $inactive) {
            if ($inactive) {
                $this->grantRolePermissions('admin', ['investigations.catalog.publish']);
                $actor->forceFill(['is_active' => false])->save();
            }
            $this->assertTablesUnchanged(['services', 'investigation_tests', 'investigation_schemas', 'audit_events'], function () use ($catalog, $actor): void {
                try {
                    $catalog->publish($this->definition(), $actor->id, 0, 'Synthetic approval', 'Approved publication');
                    $this->fail('An unauthorized publisher succeeded.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('Publisher is not authorized.', $exception->getMessage());
                }
            });
        }
    }

    public function test_an_unchanged_retry_cannot_confirm_private_publication_after_permission_revocation(): void
    {
        $actor = $this->publisher();
        $catalog = app(InvestigationCatalog::class);
        $catalog->publish($this->definition(), $actor, 0, 'Synthetic approval', 'Initial publication');
        Role::where('code', 'diagnostics-and-radiologist')->sole()->permissions()
            ->detach(Permission::where('code', 'investigations.catalog.publish')->sole()->id);
        $this->assertTablesUnchanged(['services', 'investigation_tests', 'investigation_schemas', 'audit_events'], function () use ($catalog, $actor): void {
            try {
                $catalog->publish($this->definition(), $actor, 0, 'Synthetic approval', 'Initial publication');
                $this->fail('A revoked publisher confirmed a private publication.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Publisher is not authorized.', $exception->getMessage());
            }
        });
    }

    public function test_stale_updates_and_rebound_services_cannot_change_saved_identity(): void
    {
        $actor = $this->publisher();
        $catalog = app(InvestigationCatalog::class);
        $input = $this->definition();
        $catalog->publish($input, $actor, 0, 'Synthetic approval', 'Initial publication');
        $cases = [];
        $changed = $input;
        $changed['fields']['reading']['unit'] = 'conflicting-unit';
        $cases[] = [$changed, 0];
        $changed = $input;
        $changed['service'] = ['code' => 'another-service', 'name' => 'Another service'];
        $cases[] = [$changed, 1];
        $changed = $input;
        $changed['service']['name'] = 'Different saved service name';
        $cases[] = [$changed, 1];
        $changed = $input;
        $changed['department'] = 'radiology';
        $cases[] = [$changed, 1];
        foreach ($cases as [$definition, $expected]) {
            $this->assertTablesUnchanged(['services', 'investigation_tests', 'investigation_schemas', 'audit_events'], function () use ($catalog, $actor, $definition, $expected): void {
                try {
                    $catalog->publish($definition, $actor, $expected, 'Synthetic approval', 'Changed publication');
                    $this->fail('A conflicting publication succeeded.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            });
        }
    }

    public function test_failed_audit_rolls_back_service_test_and_schema_then_same_submission_can_retry(): void
    {
        $actor = $this->publisher();
        $triggered = $this->failNextWriteTo('audit_events', 'insert');
        $catalog = app(InvestigationCatalog::class);
        $this->assertTablesUnchanged(['services', 'investigation_tests', 'investigation_schemas', 'audit_events'], function () use ($catalog, $actor): void {
            try {
                $catalog->publish($this->definition(), $actor, 0, 'Synthetic approval', 'Initial publication');
                $this->fail('The injected failure did not stop publication.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic failure after a database write.', $exception->getMessage());
            }
        });
        $this->assertTrue($triggered());
        $catalog->publish($this->definition(), $actor, 0, 'Synthetic approval', 'Initial publication');
        $this->assertDatabaseCount('investigation_schemas', 1);
    }

    #[DataProvider('invalidDefinitions')]
    public function test_malformed_definitions_leave_no_catalog_or_approval_data(string $case): void
    {
        $input = $this->definition();
        match ($case) {
            'empty' => $input = [],
            'unknown' => $input['price'] = 100,
            'department' => $input['department'] = 'invented',
            'code' => $input['code'] = 'Invalid Code',
            'blank' => $input['title'] = '   ',
            'boolean' => $input['is_active'] = 'true',
            'fields-list' => $input['fields'] = [['type' => 'text']],
            'empty-fields' => $input['fields'] = [],
            'field-key' => $input['fields']['Wrong Key'] = ['type' => 'text'],
            'type' => $input['fields']['reading']['type'] = 'script',
            'missing-unit' => $input['fields']['reading'] = ['type' => 'number'],
            'blank-unit' => $input['fields']['reading']['unit'] = '',
            'reversed-range' => $input['fields']['reading']['reference_ranges'][0]['upper'] = -1,
            'string-bound' => $input['fields']['reading']['reference_ranges'][0]['lower'] = '0',
            'range-list' => $input['fields']['reading']['reference_ranges'] = ['wrong' => []],
            'range-context' => $input['fields']['reading']['reference_ranges'][0]['population'] = ['invented'],
            'empty-range' => $input['fields']['reading']['reference_ranges'] = [['label' => 'No bounds or description']],
            'range-unit' => $input['fields']['reading']['reference_ranges'][0]['unit'] = 'another-unit',
            'numeric-text' => $input['fields']['reading']['type'] = 'text',
            'threshold-rule' => $input['fields']['reading']['max'] = 100,
            'choices' => $input['fields']['reading'] = ['type' => 'select', 'options' => []],
            'service-keys' => $input['service']['price'] = 100,
            'order' => $input['field_order'] = ['reading', 'reading', 'confirmed'],
            'format' => $input['format_version'] = 2,
        };
        $actor = $this->publisher();
        $this->assertTablesUnchanged(['services', 'investigation_tests', 'investigation_schemas', 'audit_events'], function () use ($input, $actor): void {
            try {
                app(InvestigationCatalog::class)->publish($input, $actor, 0, 'Synthetic approval', 'Initial publication');
                $this->fail('An invalid definition was accepted.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->addToAssertionCount(1);
            }
        });
    }

    public static function invalidDefinitions(): array
    {
        return array_map(static fn (string $case): array => [$case], ['empty', 'unknown', 'department', 'code', 'blank', 'boolean', 'fields-list', 'empty-fields',
            'field-key', 'type', 'missing-unit', 'blank-unit', 'reversed-range', 'string-bound', 'range-list', 'range-context', 'empty-range', 'range-unit',
            'numeric-text', 'threshold-rule', 'choices', 'service-keys', 'order', 'format']);
    }

    public function test_console_requires_approved_input_and_safe_options_then_confirms_unchanged_retry(): void
    {
        $actor = $this->publisher();
        $path = tempnam(sys_get_temp_dir(), 'investigation-definition-');
        try {
            file_put_contents($path, json_encode($this->definition(), JSON_THROW_ON_ERROR));
            $options = ['definition' => $path, 'expectedVersion' => 0, '--publisher' => $actor,
                '--approval-reference' => 'Synthetic approval', '--reason' => 'Initial approved publication'];
            $this->artisan('investigations:publish', $options)->assertSuccessful();
            $this->artisan('investigations:publish', $options)->assertSuccessful();
            $this->artisan('investigations:publish', array_replace($options, ['expectedVersion' => '1.5']))->assertFailed();
            $this->artisan('investigations:publish', array_replace($options, ['--approval-reference' => '']))->assertFailed();
            file_put_contents($path, '{invalid-json');
            $this->artisan('investigations:publish', $options)->assertFailed();
            file_put_contents($path, str_repeat('x', 262145));
            $this->artisan('investigations:publish', $options)->assertFailed();
            $this->assertDatabaseCount('investigation_schemas', 1);
        } finally {
            unlink($path);
        }
    }

    public function test_radiology_narrative_and_explicit_dimensionless_values_need_no_invented_ranges(): void
    {
        $actor = $this->publisher();
        $input = $this->definition();
        $input['department'] = 'radiology';
        $input['specimen'] = null;
        $input['fields'] = ['findings' => ['type' => 'text', 'required' => true, 'max_length' => 20000, 'group' => 'Report'],
            'count' => ['type' => 'integer', 'unit' => null, 'reference_ranges' => []],
            'classification' => ['type' => 'select', 'options' => ['Synthetic option A', 'Synthetic option B'], 'reference_ranges' => [['label' => 'Synthetic qualitative reference', 'text' => 'Approved descriptive reference']]]];
        $schema = app(InvestigationCatalog::class)->publish($input, $actor, 0, 'Synthetic approval', 'Initial publication');
        $this->assertNull($schema->schema_json['fields']['count']['unit']);
        $this->assertSame('radiology', $schema->test->service->category);
    }
}
