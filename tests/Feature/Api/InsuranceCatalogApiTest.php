<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InsuranceCatalogWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class InsuranceCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_envelope_pagination_and_archive_preserve_history(): void
    {
        $this->actor(['view', 'manage']);
        $insurer = $this->postJson('/api/insurance/insurers', ['code' => 'TEST', 'name' => 'Test catalog insurer'])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId'])
            ->assertJsonPath('data.insurer.lock_version', 1)->json('data.insurer');
        $plan = $this->postJson('/api/insurance/insurers/'.$insurer['id'].'/plans', ['code' => 'PLAN', 'name' => 'Test plan'])
            ->assertCreated()->assertJsonPath('data.plan.insurer_id', $insurer['id'])->json('data.plan');
        $this->getJson('/api/insurance/insurers?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/insurance/insurers/'.$insurer['id'].'/plans')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/insurance/plans/'.$plan['id'])->assertOk();
        $this->patchJson('/api/insurance/plans/'.$plan['id'], ['name' => 'Renamed', 'expected_version' => 1])
            ->assertOk()->assertJsonPath('data.plan.lock_version', 2);
        $this->deleteJson('/api/insurance/plans/'.$plan['id'], ['expected_version' => 2])->assertOk()->assertJsonPath('data.plan.active', false);
        $this->patchJson('/api/insurance/insurers/'.$insurer['id'], ['name' => 'Renamed insurer', 'expected_version' => 1])->assertOk();
        $this->deleteJson('/api/insurance/insurers/'.$insurer['id'], ['expected_version' => 2])->assertOk()->assertJsonPath('data.insurer.active', false);
        $this->getJson('/api/insurance/insurers?active=1')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('insurers', 1);
        $this->assertDatabaseCount('insurance_plans', 1);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'insurance_plans', 'action' => 'archived']);
    }

    public function test_version_lifecycle_has_separate_authorities_and_immutable_publication(): void
    {
        $editor = $this->actor(['view', 'manage']);
        [$insurer, $plan, $version] = $this->catalog($editor);
        $this->assertDatabaseHas('insurance_version_editors', ['insurance_plan_version_id' => $version->id, 'user_id' => $editor->id]);
        $url = '/api/insurance/plan-versions/'.$version->id;
        $this->patchJson($url, ['name' => 'Edited metadata', 'expected_version' => 1])->assertOk()->assertJsonPath('data.plan_version.lock_version', 2);
        $this->postJson($url.'/review', ['expected_version' => 2])->assertForbidden();
        $reviewer = $this->actor(['view', 'review']);
        $this->postJson($url.'/review', ['expected_version' => 2])->assertOk()->assertJsonPath('data.plan_version.status', 'reviewed');
        $this->postJson($url.'/publish', ['expected_version' => 3])->assertForbidden();
        $publisher = $this->actor(['view', 'publish']);
        $this->postJson($url.'/publish', ['expected_version' => 3])->assertOk()
            ->assertJsonPath('data.plan_version.status', 'published')->assertJsonPath('data.plan_version.benefits_configured', false)
            ->assertJsonPath('data.plan_version.reviewed_by_user_id', $reviewer->id)->assertJsonPath('data.plan_version.published_by_user_id', $publisher->id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.plan_version.name', 'Edited metadata');
        $this->getJson('/api/insurance/plans/'.$plan->id.'/versions?status=published')->assertOk()->assertJsonCount(1, 'data');
        Sanctum::actingAs($editor);
        $this->patchJson($url, ['name' => 'Cannot rewrite', 'expected_version' => 4])->assertConflict();
        $this->deleteJson($url, ['expected_version' => 4])->assertConflict();
        $this->deleteJson('/api/insurance/plans/'.$plan->id, ['expected_version' => 1])->assertOk();
        $this->deleteJson('/api/insurance/insurers/'.$insurer->id, ['expected_version' => 1])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.plan_version.name', 'Edited metadata');
        foreach (['created', 'updated', 'reviewed', 'published'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['resource_type' => 'insurance_plan_versions', 'resource_id' => (string) $version->id, 'action' => $action]);
        }
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('patient_documents', 0);
    }

    public function test_every_editor_is_ineligible_even_after_another_user_edits(): void
    {
        $first = $this->actor(['manage', 'review', 'publish']);
        [, , $version] = $this->catalog($first);
        $second = $this->actor(['manage', 'review', 'publish']);
        $url = '/api/insurance/plan-versions/'.$version->id;
        $this->patchJson($url, ['name' => 'Another editor', 'expected_version' => 1])->assertOk();
        foreach ([$first, $second] as $editor) {
            Sanctum::actingAs($editor);
            $this->postJson($url.'/review', ['expected_version' => 2])->assertForbidden();
        }
        $this->actor(['review', 'publish']);
        $this->postJson($url.'/review', ['expected_version' => 2])->assertOk();
        Sanctum::actingAs($first);
        $this->postJson($url.'/publish', ['expected_version' => 3])->assertForbidden();
        $this->assertDatabaseCount('insurance_version_editors', 2);
    }

    public function test_privileged_editor_cannot_review_or_publish_through_direct_service_calls(): void
    {
        $editor = $this->actor(['view', 'manage', 'review', 'publish', '*', 'insurance_catalog.*'], Role::ADMIN);
        [, , $version] = $this->catalog($editor);
        try {
            $this->workflow(fn ($w) => $w->transition($version, ['expected_version' => 1], $editor, 'review'));
            $this->fail('Privileged editors cannot review their own version.');
        } catch (AuthorizationException) {
            $this->assertSame('draft', $version->refresh()->status);
        }
        $reviewer = $this->actor(['review']);
        $reviewed = $this->workflow(fn ($w) => $w->transition($version, ['expected_version' => 1], $reviewer, 'review'));
        try {
            $this->workflow(fn ($w) => $w->transition($reviewed, ['expected_version' => 2], $editor, 'publish'));
            $this->fail('Privileged editors cannot publish their own version.');
        } catch (AuthorizationException) {
            $this->assertSame('reviewed', $reviewed->refresh()->status);
        }
    }

    public function test_view_permission_does_not_grant_catalog_mutations(): void
    {
        $editor = $this->actor(['manage']);
        [$insurer, $plan, $version] = $this->catalog($editor);
        $this->actor(['view']);
        $this->getJson('/api/insurance/insurers')->assertOk();
        $this->getJson('/api/insurance/plans/'.$plan->id)->assertOk();
        $this->getJson('/api/insurance/plan-versions/'.$version->id)->assertOk();
        $this->patchJson('/api/insurance/insurers/'.$insurer->id, ['name' => 'Not allowed', 'expected_version' => 1])->assertForbidden();
        $this->postJson('/api/insurance/plans/'.$plan->id.'/versions', $this->versionInput())->assertForbidden();
        foreach (['review', 'publish'] as $action) {
            $this->postJson('/api/insurance/plan-versions/'.$version->id.'/'.$action, ['expected_version' => 1])->assertForbidden();
        }
    }

    public function test_additive_migration_and_empty_rollback_preserve_existing_financial_rows(): void
    {
        $invoice = \App\Models\Invoice::factory()->issued()->create();
        \App\Models\Payment::factory()->for($invoice)->create();
        $before = [];
        foreach (['invoices', 'invoice_items', 'payments'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        $migration->down();
        $migration->up();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertDatabaseCount('insurers', 0);
        $this->assertDatabaseCount('insurance_plan_versions', 0);
    }

    public function test_admin_and_wildcards_do_not_bypass_explicit_catalog_permissions(): void
    {
        foreach ([['*'], ['insurance_catalog.*'], ['review']] as $grants) {
            $this->actor($grants, Role::ADMIN);
            $this->postJson('/api/insurance/insurers', ['code' => 'DENIED', 'name' => 'Denied'])->assertForbidden();
            $this->getJson('/api/insurance/insurers')->assertForbidden();
        }
        $this->assertDatabaseCount('insurers', 0);
    }

    public function test_authentication_and_invalid_input_are_rejected_without_side_effects(): void
    {
        $this->getJson('/api/insurance/insurers')->assertUnauthorized();
        $editor = $this->actor(['view', 'manage']);
        foreach ([['code' => 'lowercase', 'name' => 'Bad'], ['code' => 'BAD', 'name' => '   '], ['code' => 'BAD', 'name' => 'Bad', 'tenant_id' => 1], ['code' => 'BAD', 'name' => 'Bad', 'benefit_rules' => []]] as $payload) {
            $this->postJson('/api/insurance/insurers', $payload)->assertUnprocessable();
        }
        [, $plan] = $this->catalog($editor, false);
        $count = AuditLog::count();
        foreach ([['effective_to' => '2026-01-01'], ['effective_from' => '2026-02-30'], ['currency' => 'jmd'], ['status' => 'published'], ['published_by_user_id' => $editor->id], ['document_ids' => [1]], ['coverage_percent' => 80]] as $change) {
            $this->postJson('/api/insurance/plans/'.$plan->id.'/versions', [...$this->versionInput(), ...$change])->assertUnprocessable();
        }
        $this->assertDatabaseCount('insurance_plan_versions', 0);
        $this->assertSame($count, AuditLog::count());
        $this->getJson('/api/insurance/insurers?per_page=101')->assertUnprocessable();
    }

    public function test_duplicate_codes_are_scoped_and_parent_ownership_is_immutable(): void
    {
        $editor = $this->actor(['view', 'manage']);
        [$insurer, $plan] = $this->catalog($editor, false);
        $this->postJson('/api/insurance/insurers', ['code' => $insurer->code, 'name' => 'Duplicate'])->assertUnprocessable();
        $this->postJson('/api/insurance/insurers/'.$insurer->id.'/plans', ['code' => $plan->code, 'name' => 'Duplicate'])->assertUnprocessable();
        $other = $this->workflow(fn ($w) => $w->createInsurer(['code' => 'OTHER', 'name' => 'Another insurer'], $editor));
        $this->postJson('/api/insurance/insurers/'.$other->id.'/plans', ['code' => $plan->code, 'name' => 'Allowed scoped code'])->assertCreated();
        $this->patchJson('/api/insurance/plans/'.$plan->id, ['insurer_id' => $other->id, 'expected_version' => 1])->assertUnprocessable();
        $this->patchJson('/api/insurance/insurers/'.$insurer->id, ['code' => 'REWRITE', 'expected_version' => 1])->assertUnprocessable();
    }

    public function test_stale_versions_withdrawal_and_reviewed_versions_are_protected(): void
    {
        $editor = $this->actor(['view', 'manage']);
        [, $plan, $version] = $this->catalog($editor);
        $url = '/api/insurance/plan-versions/'.$version->id;
        $this->patchJson($url, ['effective_to' => '2026-01-01', 'expected_version' => 1])->assertUnprocessable();
        $this->patchJson($url, ['name' => 'Updated', 'expected_version' => 1])->assertOk();
        $count = AuditLog::count();
        $this->patchJson($url, ['name' => 'Stale', 'expected_version' => 1])->assertConflict();
        $this->assertSame($count, AuditLog::count());
        $this->actor(['review']);
        $this->postJson($url.'/review', ['expected_version' => 1])->assertConflict();
        $this->postJson($url.'/review', ['expected_version' => 2])->assertOk();
        Sanctum::actingAs($editor);
        $this->patchJson($url, ['name' => 'Reviewed rewrite', 'expected_version' => 3])->assertConflict();
        $this->deleteJson($url, ['expected_version' => 3])->assertOk()->assertJsonPath('data.plan_version.status', 'withdrawn');
        $this->patchJson($url, ['name' => 'Withdrawn rewrite', 'expected_version' => 4])->assertConflict();
        $this->postJson('/api/insurance/plans/'.$plan->id.'/versions', $this->versionInput())->assertCreated()->assertJsonPath('data.plan_version.version', 2);
    }

    public function test_published_effective_ranges_cannot_overlap_and_boundary_is_inclusive(): void
    {
        $editor = $this->actor(['view', 'manage']);
        [, $plan, $first] = $this->catalog($editor);
        $second = $this->workflow(fn ($w) => $w->createVersion($plan, [...$this->versionInput(), 'effective_from' => '2026-12-31', 'effective_to' => null], $editor));
        $third = $this->workflow(fn ($w) => $w->createVersion($plan, [...$this->versionInput(), 'effective_from' => '2027-01-01', 'effective_to' => null], $editor));
        $this->actor(['view', 'review', 'publish']);
        foreach ([$first, $second, $third] as $version) {
            $this->postJson('/api/insurance/plan-versions/'.$version->id.'/review', ['expected_version' => 1])->assertOk();
        }
        $this->postJson('/api/insurance/plan-versions/'.$first->id.'/publish', ['expected_version' => 2])->assertOk();
        $this->postJson('/api/insurance/plan-versions/'.$second->id.'/publish', ['expected_version' => 2])->assertConflict();
        $this->postJson('/api/insurance/plan-versions/'.$third->id.'/publish', ['expected_version' => 2])->assertOk();
    }

    public function test_tenant_routes_queries_headers_and_shared_identity_grants_are_isolated(): void
    {
        $editor = $this->actor(['view', 'manage']);
        [$insurer, $plan, $version] = $this->catalog($editor);
        $other = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($other, function () use ($insurer) {
            $user = $this->actor(['view', 'manage']);

            return $this->workflow(fn ($w) => $w->createInsurer(['code' => $insurer->code, 'name' => 'Foreign'], $user));
        });
        Sanctum::actingAs($editor);
        $this->getJson('/api/insurance/insurers/'.$foreign->id)->assertNotFound();
        $this->getJson('/api/insurance/insurers', ['X-Tenant-Id' => (string) $other->id])->assertForbidden();
        $editor->tenants()->attach($other->id);
        $headers = ['X-Tenant-Id' => (string) $other->id];
        $this->getJson('/api/insurance/insurers', $headers)->assertForbidden();
        foreach (['insurers/'.$insurer->id, 'plans/'.$plan->id, 'plan-versions/'.$version->id] as $path) {
            $this->getJson('/api/insurance/'.$path, $headers)->assertNotFound();
            $this->patchJson('/api/insurance/'.$path, ['name' => 'Foreign write', 'expected_version' => 1], $headers)->assertNotFound();
        }
        $this->getJson('/api/insurance/insurers', ['X-Tenant-Id' => (string) $insurer->tenant_id])->assertOk()->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_workflow_requires_explicit_context_permissions_and_foreign_object_checks(): void
    {
        $editor = $this->actor(['manage']);
        [$insurer, $plan, $version] = $this->catalog($editor);
        $outsider = User::factory()->create();
        $foreign = Tenant::factory()->create();
        foreach ([
            fn ($w) => $w->createInsurer(['code' => 'BAD', 'name' => 'Bad'], $outsider),
            fn ($w) => $w->updateInsurer($insurer, ['expected_version' => 1, 'name' => 'Bad'], $outsider),
            fn ($w) => $w->createPlan($insurer, ['code' => 'BAD', 'name' => 'Bad'], $outsider),
            fn ($w) => $w->updatePlan($plan, ['expected_version' => 1, 'name' => 'Bad'], $outsider),
            fn ($w) => $w->createVersion($plan, $this->versionInput(), $outsider),
            fn ($w) => $w->updateVersion($version, ['expected_version' => 1, 'name' => 'Bad'], $outsider),
            fn ($w) => $w->insurers([], $outsider),
            fn ($w) => $w->plans($insurer, [], $outsider),
            fn ($w) => $w->versions($plan, [], $outsider),
            fn ($w) => $w->version($version, $outsider),
            fn ($w) => $w->transition($version, ['expected_version' => 1], $outsider, 'review'),
            fn ($w) => $w->transition($version, ['expected_version' => 1], $outsider, 'publish'),
            fn ($w) => $w->transition($version, ['expected_version' => 1], $outsider, 'withdraw'),
        ] as $call) {
            try {
                $this->workflow($call);
                $this->fail('Direct service authorization required.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        app(TenantContext::class)->run($foreign, function () use ($editor, $version) {
            try {
                app(InsuranceCatalogWorkflow::class)->version($version, $editor);
                $this->fail('Foreign object must be denied.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        });
        try {
            app(InsuranceCatalogWorkflow::class)->insurers([], $editor);
            $this->fail('A direct caller must establish explicit clinic context.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('context', $error->getMessage());
        }
    }

    public function test_database_guards_reject_cross_tenant_bulk_writes_and_published_mutation(): void
    {
        $editor = $this->actor(['manage']);
        [$insurer, $plan, $version] = $this->catalog($editor);
        $reviewer = $this->actor(['review', 'publish']);
        $this->workflow(function ($w) use ($version, $reviewer) {
            $v = $w->transition($version, ['expected_version' => 1], $reviewer, 'review');
            $w->transition($v, ['expected_version' => 2], $reviewer, 'publish');
        });
        $foreign = Tenant::factory()->create();
        $this->rejectSql(fn () => DB::table('insurance_plan_versions')->where('id', $version->id)->update(['name' => 'Raw rewrite', 'lock_version' => 4]));
        $this->rejectSql(fn () => DB::table('insurance_plan_versions')->where('id', $version->id)->delete());
        $this->rejectSql(fn () => DB::table('insurance_plans')->where('id', $plan->id)->update(['tenant_id' => $foreign->id, 'lock_version' => 2]));
        $this->rejectSql(fn () => DB::table('insurance_plans')->insert(['tenant_id' => $foreign->id, 'insurer_id' => $insurer->id, 'code' => 'FOREIGN', 'name' => 'Bad', 'lock_version' => 1]));
        $this->rejectSql(fn () => DB::table('insurance_version_editors')->where('insurance_plan_version_id', $version->id)->delete());
        $this->rejectSql(fn () => DB::table('insurers')->where('id', $insurer->id)->delete());
        $this->rejectSql(fn () => DB::table('insurance_plans')->where('id', $plan->id)->delete());
        $migration = require database_path('migrations/2026_11_13_000001_create_insurance_catalog.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
    }

    public function test_audit_failure_rolls_back_catalog_version_and_editor_tracking(): void
    {
        $editor = $this->actor(['manage']);
        [$insurer, $plan] = $this->catalog($editor, false);
        $mock = Mockery::mock(AuditLogService::class);
        $mock->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->app->instance(AuditLogService::class, $mock);
        try {
            $this->workflow(fn ($w) => $w->createVersion($plan, $this->versionInput(), $editor));
            $this->fail('Audit failure should abort.');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('insurance_plan_versions', 0);
        $this->assertDatabaseCount('insurance_version_editors', 0);
        $this->assertDatabaseCount('insurers', 1);
    }

    protected function actor(array $actions, string $roleSlug = 'catalog-test'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $roleSlug === Role::ADMIN ? Role::ADMIN : $roleSlug.'-'.$user->id], ['name' => 'Test catalog role']);
        foreach ($actions as $action) {
            $slug = str_contains($action, '.') || $action === '*' ? $action : 'insurance_catalog.'.$action;
            $permission = Permission::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function catalog(User $editor, bool $withVersion = true): array
    {
        return $this->workflow(function ($w) use ($editor, $withVersion) {
            $insurer = $w->createInsurer(['code' => 'TEST', 'name' => 'Test catalog insurer'], $editor);
            $plan = $w->createPlan($insurer, ['code' => 'PLAN', 'name' => 'Test plan'], $editor);

            return [$insurer, $plan, $withVersion ? $w->createVersion($plan, $this->versionInput(), $editor) : null];
        });
    }

    protected function versionInput(): array
    {
        return ['name' => 'Test metadata version', 'currency' => 'JMD', 'effective_from' => '2026-02-01', 'effective_to' => '2026-12-31'];
    }

    protected function workflow(callable $callback): mixed
    {
        $tenant = Tenant::findOrFail(app(TenantContext::class)->id());

        return app(TenantContext::class)->run($tenant, fn () => $callback(app(InsuranceCatalogWorkflow::class)));
    }

    protected function rejectSql(callable $callback): void
    {
        DB::beginTransaction();
        try {
            $callback();
            $this->fail('Database guard should reject the write.');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }
}
