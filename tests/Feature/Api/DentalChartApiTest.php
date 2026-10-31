<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\DentalChartRevision;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\DentalChartWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DentalChartApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_authentication_is_required(string $method, string $suffix): void
    {
        $patient = Patient::factory()->create();
        $this->json($method, str_replace('{patient}', $patient->id, $suffix))->assertUnauthorized();
    }

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/dental-chart/metadata'], ['GET', '/api/patients/{patient}/dental-chart'],
            ['GET', '/api/patients/{patient}/dental-chart/history'],
            ['GET', '/api/patients/{patient}/dental-chart/revisions/1'],
            ['POST', '/api/patients/{patient}/dental-chart/revisions'],
        ];
    }

    public function test_blank_reads_do_not_create_charts_or_audits_and_metadata_describes_both_dentitions(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $this->getJson($this->url($patient))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.chart.version', 0)->assertJsonPath('data.chart.teeth', [])
            ->assertJsonPath('data.chart.patient_id', $patient->id)->assertJsonPath('data.chart.numbering_system', 'universal')
            ->assertJsonStructure(['success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId']);
        $this->getJson('/api/dental-chart/metadata')->assertOk()->assertJsonCount(32, 'data.dentitions.adult')
            ->assertJsonCount(20, 'data.dentitions.primary')->assertJsonPath('data.dentitions.adult.0.tooth', '8');
        $this->getJson('/api/dental-chart/metadata')->assertOk()
            ->assertJsonPath('data.dentitions.adult.0.tooth', '8')->assertJsonPath('data.dentitions.primary.0.tooth', 'E');
        $this->assertDatabaseCount('dental_chart_revisions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_mutations_record_provider_actor_timestamp_conditions_treatments_and_immutable_history(): void
    {
        $this->travelTo(now()->startOfSecond());
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $provider = Dentist::factory()->create();
        $treatment = Treatment::factory()->create();
        $note = ClinicalNote::factory()->create(['patient_id' => $patient->id, 'dentist_id' => $provider->id, 'appointment_id' => null]);
        $payload = $this->payload($provider);
        $payload['clinical_note_id'] = $note->id;
        $payload['teeth'][0]['findings'][0]['treatment_id'] = $treatment->id;
        $first = $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()
            ->assertJsonPath('data.chart.version', 1)->assertJsonPath('data.chart.dentist_id', $provider->id)
            ->assertJsonPath('data.chart.author_user_id', $actor->id)->assertJsonPath('data.chart.clinical_note_id', $note->id)
            ->assertJsonPath('data.chart.teeth.0.findings.0.treatment_id', $treatment->id)
            ->assertJsonPath('data.chart.recorded_at', now('UTC')->toISOString())->json('data.chart');
        $this->travel(1)->seconds();
        $nextProvider = Dentist::factory()->create();
        $payload['expected_version'] = 1;
        $payload['dentist_id'] = $nextProvider->id;
        unset($payload['clinical_note_id']);
        $payload['teeth'][0]['status'] = 'extracted';
        $payload['teeth'][0]['findings'] = [];
        $payload['reason'] = 'Updated examination';
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()->assertJsonPath('data.chart.version', 2);
        $this->getJson($this->url($patient))->assertOk()->assertJsonPath('data.chart.teeth.0.status', 'extracted')
            ->assertJsonPath('data.chart.dentist_id', $nextProvider->id)->assertJsonPath('data.chart.version', 2);
        $this->assertEquals($first, $this->getJson($this->url($patient, '/revisions/1').'?numbering_system=fdi')->assertOk()->json('data.chart'));
        $this->getJson($this->url($patient, '/history').'?per_page=1')->assertOk()->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.revisions.0.version', 2)->assertJsonMissingPath('data.revisions.0.teeth');
        $this->getJson($this->url($patient, '/history').'?per_page=1&page=2')->assertOk()->assertJsonPath('data.revisions.0.version', 1);
        $this->assertSame(['revision_created', 'revision_created'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(['dental_charts'], AuditLog::distinct()->pluck('resource_type')->all());
        $this->assertStringNotContainsString('Updated examination', AuditLog::get()->toJson());
        $this->assertStringNotContainsString('caries', AuditLog::get()->toJson());
    }

    public function test_adult_primary_and_universal_numbering_share_canonical_state_without_losing_history(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $payload['numbering_system'] = 'universal';
        $payload['teeth'][0]['tooth'] = '1';
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()->assertJsonPath('data.chart.teeth.0.tooth', '1');
        $this->assertSame('18', DentalChartRevision::sole()->teeth[0]['tooth']);
        $this->getJson($this->url($patient))->assertOk()->assertJsonPath('data.chart.teeth.0.tooth', '1');
        $this->getJson($this->url($patient).'?numbering_system=fdi')->assertOk()->assertJsonPath('data.chart.teeth.0.tooth', '18');
        $payload['dentition'] = 'primary';
        $payload['teeth'][0]['tooth'] = 'A';
        $payload['teeth'][0]['status'] = 'missing';
        $payload['teeth'][0]['findings'][0]['code'] = 'custom_condition';
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()->assertJsonPath('data.chart.version', 1)
            ->assertJsonPath('data.chart.teeth.0.tooth', 'A')->assertJsonPath('data.chart.teeth.0.status', 'missing');
        $this->getJson($this->url($patient).'?dentition=primary&numbering_system=fdi')->assertOk()->assertJsonPath('data.chart.teeth.0.tooth', '55');
        $this->getJson($this->url($patient, '/revisions/1').'?dentition=primary&numbering_system=universal')
            ->assertOk()->assertJsonPath('data.chart.teeth.0.findings.0.code', 'custom_condition');
        $this->assertDatabaseCount('dental_chart_revisions', 2);
    }

    public function test_product_defaults_to_universal_for_adult_and_primary_mutations_and_reads(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        unset($payload['numbering_system']);
        foreach (['adult' => '1', 'primary' => 'A'] as $dentition => $tooth) {
            $payload['dentition'] = $dentition;
            $payload['teeth'][0]['tooth'] = $tooth;
            $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()
                ->assertJsonPath('data.chart.numbering_system', 'universal')->assertJsonPath('data.chart.teeth.0.tooth', $tooth);
            $this->getJson($this->url($patient).'?dentition='.$dentition)->assertOk()
                ->assertJsonPath('data.chart.numbering_system', 'universal')->assertJsonPath('data.chart.teeth.0.tooth', $tooth);
        }
    }

    public function test_stale_versions_conflict_instead_of_overwriting_and_explicit_empty_state_is_historical(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated();
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->assertDatabaseCount('dental_chart_revisions', 1);
        $this->assertDatabaseCount('audit_logs', 1);
        $payload['expected_version'] = 1;
        $payload['teeth'] = [];
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()->assertJsonPath('data.chart.teeth', []);
        $this->getJson($this->url($patient, '/revisions/1'))->assertOk()->assertJsonCount(1, 'data.chart.teeth');
        $this->getJson($this->url($patient, '/revisions/99'))->assertNotFound();
        $this->assertDatabaseCount('audit_logs', 2);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_chart_data_and_server_attribution_are_rejected(string $field, mixed $value, string $error): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        Arr::set($payload, $field, $value);
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertDatabaseCount('dental_chart_revisions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidInputs(): array
    {
        return [
            ['dentition', 'mixed', 'dentition'], ['numbering_system', 'guess', 'numbering_system'],
            ['expected_version', -1, 'expected_version'], ['expected_version', null, 'expected_version'],
            ['reason', '', 'reason'], ['reason', str_repeat('x', 1001), 'reason'],
            ['dentist_id', 999999, 'dentist_id'], ['teeth', null, 'teeth'],
            ['teeth', ['key' => ['tooth' => '18', 'status' => 'present', 'findings' => []]], 'teeth'],
            ['teeth.0.tooth', '55', 'teeth.0.tooth'], ['teeth.0.tooth', '19', 'teeth.0.tooth'],
            ['teeth.0.tooth', true, 'teeth.0.tooth'], ['teeth.0.status', 'unknown', 'teeth.0.status'],
            ['teeth.0.findings.0.code', 'Free text!', 'teeth.0.findings.0.code'],
            ['teeth.0.findings.0.status', 'unknown', 'teeth.0.findings.0.status'],
            ['teeth.0.findings.0.surfaces', ['I'], 'teeth.0.findings.0.surfaces'],
            ['teeth.0.findings.0.surfaces', ['M', 'M'], 'teeth.0.findings.0.surfaces'],
            ['teeth.0.findings.0.surfaces', ['F', 'B'], 'teeth.0.findings.0.surfaces'],
            ['teeth.0.findings.0.surfaces', ['ROOT'], 'teeth.0.findings.0.surfaces.0'],
            ['teeth.0.findings.0.treatment_id', 999999, 'teeth.0.findings.0.treatment_id'],
            ['teeth.0.findings.0.note', str_repeat('x', 1001), 'teeth.0.findings.0.note'],
            ['version', 5, 'version'], ['patient_id', 1, 'patient_id'], ['author_user_id', 1, 'author_user_id'],
            ['recorded_at', '2000-01-01', 'recorded_at'], ['tenant_id', 1, 'tenant_id'],
            ['teeth.0.extra', 'ignored?', 'teeth.0'],
        ];
    }

    #[DataProvider('forbiddenWriters')]
    public function test_non_clinical_roles_cannot_bypass_write_policies_with_permission_wildcards(?string $role, string $permission, bool $alsoReceptionist = false): void
    {
        $actor = $this->actor($role, $permission);
        if ($alsoReceptionist) {
            $actor->roles()->attach(Role::firstOrCreate(['slug' => Role::RECEPTIONIST], ['name' => 'Receptionist']));
        }
        $patient = Patient::factory()->create();
        $this->postJson($this->url($patient, '/revisions'), $this->payload(Dentist::factory()->create()))->assertForbidden();
        $this->assertDatabaseCount('dental_chart_revisions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function forbiddenWriters(): array
    {
        return [
            [Role::RECEPTIONIST, '*'], [Role::DENTIST, '*', true], [Role::AUDITOR, '*'],
            [Role::HYGIENIST, 'dental_charts.create'], [null, '*'], [null, 'create'], [null, 'dental_charts.*'],
        ];
    }

    public function test_explicit_read_permission_exposes_history_but_does_not_authorize_writes(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated();
        $this->actor(null, 'dental_charts.view');
        foreach (['', '/history', '/revisions/1'] as $suffix) {
            $this->getJson($this->url($patient, $suffix))->assertOk();
        }
        $this->postJson($this->url($patient, '/revisions'), [...$payload, 'expected_version' => 1])->assertForbidden();
        $this->actor(null);
        $this->getJson($this->url($patient))->assertForbidden();
    }

    public function test_cross_tenant_routes_relationships_and_catalog_links_are_rejected(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $tenant = Tenant::factory()->create();
        [$otherPatient, $otherProvider, $treatment, $note] = app(TenantContext::class)->run($tenant, fn () => [
            Patient::factory()->create(), Dentist::factory()->create(), Treatment::factory()->create(), ClinicalNote::factory()->create(),
        ]);
        foreach (['', '/history', '/revisions/1'] as $suffix) {
            $this->getJson($this->url($otherPatient, $suffix))->assertNotFound();
        }
        $this->postJson($this->url($otherPatient, '/revisions'), $payload)->assertNotFound();
        $this->postJson($this->url($patient, '/revisions'), [...$payload, 'dentist_id' => $otherProvider->id])->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $this->postJson($this->url($patient, '/revisions'), [...$payload, 'clinical_note_id' => $note->id])->assertUnprocessable()->assertJsonValidationErrors('clinical_note_id');
        $payload['teeth'][0]['findings'][0]['treatment_id'] = $treatment->id;
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors('teeth.0.findings.0.treatment_id');
        $this->getJson($this->url($otherPatient), ['X-Tenant-Id' => (string) $tenant->id])->assertForbidden();
        $this->assertDatabaseCount('dental_chart_revisions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_clinical_note_must_match_patient_and_provider_and_not_be_deleted(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $provider = Dentist::factory()->create();
        $payload = $this->payload($provider);
        $note = ClinicalNote::factory()->create(['appointment_id' => null]);
        $payload['clinical_note_id'] = $note->id;
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors('clinical_note_id');
        $note->update(['patient_id' => $patient->id, 'dentist_id' => $provider->id]);
        $note->delete();
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors('clinical_note_id');
    }

    public function test_two_clinics_keep_overlapping_tooth_states_and_audits_isolated(): void
    {
        $localActor = $this->actor();
        $localPatient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $this->postJson($this->url($localPatient, '/revisions'), $payload)->assertCreated();
        $tenant = Tenant::factory()->create();
        [$foreignActor, $foreignPatient] = app(TenantContext::class)->run($tenant, function (): array {
            $actor = $this->actor();
            $patient = Patient::factory()->create();
            $input = $this->payload(Dentist::factory()->create());
            $input['reason'] = 'ForeignSecret';
            $input['teeth'][0]['findings'][0]['code'] = 'restored';
            app(DentalChartWorkflow::class)->append($patient, $input, $actor);

            return [$actor, $patient];
        });
        $this->withHeader('X-Tenant-Id', (string) $tenant->id);
        $this->getJson($this->url($foreignPatient))->assertOk()->assertJsonPath('data.chart.teeth.0.findings.0.code', 'restored');
        $this->getJson($this->url($localPatient))->assertNotFound();
        Sanctum::actingAs($localActor);
        $this->withHeader('X-Tenant-Id', (string) app(TenantContext::class)->id());
        $this->getJson($this->url($localPatient))->assertOk()->assertJsonPath('data.chart.teeth.0.findings.0.code', 'caries')->assertDontSee('ForeignSecret');
        $this->getJson($this->url($localPatient, '/history'))->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertDontSee('ForeignSecret');
        $this->getJson($this->url($foreignPatient, '/history'))->assertNotFound();
        $this->assertSame(1, DentalChartRevision::count());
        $this->assertSame(1, AuditLog::where('resource_type', 'dental_charts')->count());
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'actor_id' => $foreignActor->id, 'resource_type' => 'dental_charts']);
    }

    public function test_raw_inserts_cannot_forge_chart_relationships_or_author_membership(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $revision = app(DentalChartWorkflow::class)->append($patient, $this->payload(Dentist::factory()->create()), $actor);
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => [
            'patient_id' => Patient::factory()->create()->id, 'dentist_id' => Dentist::factory()->create()->id,
            'author_user_id' => User::factory()->create()->id, 'clinical_note_id' => ClinicalNote::factory()->create()->id,
        ]);
        foreach ($foreign as $column => $id) {
            $attributes = $revision->getAttributes();
            unset($attributes['id']);
            $attributes['version'] = 2;
            $attributes[$column] = $id;
            try {
                DB::beginTransaction();
                DB::table('dental_chart_revisions')->insert($attributes);
                $this->fail('Cross-clinic chart relationship was stored.');
            } catch (QueryException) {
                DB::rollBack();
                $this->assertDatabaseCount('dental_chart_revisions', 1);
            }
        }
    }

    public function test_surface_aliases_whole_tooth_findings_and_duplicate_conditions_are_handled_explicitly(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $payload['teeth'][0]['findings'] = [
            ['code' => 'restored', 'status' => 'active', 'surfaces' => ['F', 'P']],
            ['code' => 'custom_condition', 'status' => 'resolved', 'surfaces' => []],
        ];
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()
            ->assertJsonPath('data.chart.teeth.0.findings.0.surfaces', [])
            ->assertJsonPath('data.chart.teeth.0.findings.1.surfaces', ['B', 'L']);
        $payload['expected_version'] = 1;
        $payload['teeth'][0]['findings'][] = $payload['teeth'][0]['findings'][0];
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors('teeth.0.findings.2.code');
        $payload['teeth'][0]['findings'] = [];
        $payload['teeth'][] = $payload['teeth'][0];
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertUnprocessable()->assertJsonValidationErrors('teeth.1.tooth');
        $this->assertDatabaseCount('dental_chart_revisions', 1);
    }

    public function test_audit_failure_rolls_back_revision_and_allows_clean_retry(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogService::class, $audit);
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertStatus(500);
        $this->assertDatabaseCount('dental_chart_revisions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->app->forgetInstance(AuditLogService::class);
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated()->assertJsonPath('data.chart.version', 1);
    }

    public function test_direct_workflow_calls_enforce_permissions_and_patient_ownership(): void
    {
        $actor = $this->actor(null);
        $patient = Patient::factory()->create();
        $payload = $this->payload(Dentist::factory()->create());
        try {
            app(DentalChartWorkflow::class)->append($patient, $payload, $actor);
            $this->fail('Unprivileged service call was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('dental_chart_revisions', 0);
        }
        $actor = $this->actor();
        $tenant = Tenant::factory()->create();
        $foreignPatient = app(TenantContext::class)->run($tenant, fn () => Patient::factory()->create());
        try {
            app(DentalChartWorkflow::class)->append($foreignPatient, $payload, $actor);
            $this->fail('Foreign patient instance was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('dental_chart_revisions', 0);
            $this->assertDatabaseCount('audit_logs', 0);
        }
    }

    public function test_empty_migration_rollback_and_reapply_preserve_phase_one_records(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::rollBack();
            RefreshDatabaseState::$migrated = false;
        }
        $patient = Patient::factory()->create();
        $before = $patient->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_10_23_000002_create_dental_chart_revisions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('dental_chart_revisions'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('dental_chart_revisions'));
        $this->assertEquals($before, $patient->fresh()->getAttributes());
        $this->assertDatabaseCount('dental_chart_revisions', 0);
    }

    #[DataProvider('historyMutations')]
    public function test_history_cannot_be_rewritten_through_models_builders_or_sql(string $operation): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $revision = app(DentalChartWorkflow::class)->append($patient, $this->payload(Dentist::factory()->create()), $actor);
        $before = $revision->fresh()->getAttributes();
        try {
            DB::beginTransaction();
            match ($operation) {
                'model.update' => $revision->update(['reason' => 'Rewritten']),
                'model.quiet' => $revision->forceFill(['reason' => 'Rewritten'])->saveQuietly(),
                'model.delete' => $revision->delete(),
                'builder.update' => DentalChartRevision::whereKey($revision->id)->update(['reason' => 'Rewritten']),
                'builder.delete' => DentalChartRevision::whereKey($revision->id)->delete(),
                'builder.upsert' => DentalChartRevision::upsert([['id' => $revision->id, 'reason' => 'Rewritten']], ['id']),
                'sql.update' => DB::table('dental_chart_revisions')->where('id', $revision->id)->update(['reason' => 'Rewritten']),
                'sql.delete' => DB::table('dental_chart_revisions')->where('id', $revision->id)->delete(),
                'sql.replace' => $this->replaceRevision($before),
            };
            $this->fail('Historical state was changed.');
        } catch (LogicException|QueryException) {
            DB::rollBack();
            $this->assertEquals($before, $revision->fresh()->getAttributes());
        }
    }

    public static function historyMutations(): array
    {
        return array_map(fn ($operation) => [$operation], ['model.update', 'model.quiet', 'model.delete', 'builder.update', 'builder.delete', 'builder.upsert', 'sql.update', 'sql.delete', 'sql.replace']);
    }

    public function test_archival_and_staff_removal_preserve_chart_history_and_migration_rollback_refuses_data_loss(): void
    {
        $author = $this->actor(Role::ADMIN);
        $patient = Patient::factory()->create();
        $provider = Dentist::factory()->create();
        $payload = $this->payload($provider);
        $this->postJson($this->url($patient, '/revisions'), $payload)->assertCreated();
        $this->deleteJson('/api/dentists/'.$provider->id)->assertUnprocessable();
        $this->deleteJson('/api/patients/'.$patient->id)->assertOk();
        $this->getJson($this->url($patient))->assertOk()->assertJsonPath('data.chart.version', 1);
        $this->postJson($this->url($patient, '/revisions'), [...$payload, 'expected_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('patient');
        $this->actor(Role::ADMIN);
        $this->deleteJson('/api/users/'.$author->id)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $author->id]);
        $this->assertFalse($author->isMemberOfCurrentTenant());
        $this->assertSame($author->id, DentalChartRevision::sole()->author_user_id);
        $migration = require database_path('migrations/2026_10_23_000002_create_dental_chart_revisions.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    private function actor(?string $role = Role::DENTIST, ?string $permission = null): User
    {
        $user = User::factory()->create();
        $assigned = Role::firstOrCreate(['slug' => $role ?? 'custom-'.$user->id], ['name' => $role ?? 'Custom']);
        if ($permission !== null) {
            $assigned->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['slug' => $permission], ['name' => $permission])->id]);
        }
        $user->roles()->attach($assigned);
        Sanctum::actingAs($user);

        return $user;
    }

    private function url(Patient $patient, string $suffix = ''): string
    {
        return '/api/patients/'.$patient->id.'/dental-chart'.$suffix;
    }

    private function payload(Dentist $provider): array
    {
        return [
            'dentition' => 'adult', 'numbering_system' => 'fdi', 'expected_version' => 0, 'dentist_id' => $provider->id, 'reason' => 'Initial examination',
            'teeth' => [['tooth' => '18', 'status' => 'present', 'findings' => [['code' => 'caries', 'status' => 'active', 'surfaces' => ['O', 'M']]]]],
        ];
    }

    private function replaceRevision(array $attributes): void
    {
        $attributes['reason'] = 'Rewritten';
        $grammar = DB::connection()->getQueryGrammar();
        $columns = implode(', ', array_map(fn ($column) => $grammar->wrap($column), array_keys($attributes)));
        $parameters = implode(', ', array_fill(0, count($attributes), '?'));
        $sql = DB::getDriverName() === 'pgsql'
            ? 'INSERT INTO dental_chart_revisions ('.$columns.') VALUES ('.$parameters.') ON CONFLICT (id) DO UPDATE SET reason = EXCLUDED.reason'
            : 'REPLACE INTO dental_chart_revisions ('.$columns.') VALUES ('.$parameters.')';
        DB::statement($sql, array_values($attributes));
    }
}
