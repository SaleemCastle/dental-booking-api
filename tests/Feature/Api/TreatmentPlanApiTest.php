<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Dentist;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\TreatmentPlanWorkflow;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TreatmentPlanApiTest extends TestCase
{
    use RefreshDatabase;

    public static function endpoints(): array
    {
        return [
            ['GET', '/api/patients/{patient}/treatment-plans'],
            ['POST', '/api/patients/{patient}/treatment-plans'],
            ['GET', '/api/treatment-plans/{plan}'],
            ['PUT', '/api/treatment-plans/{plan}'],
            ['PATCH', '/api/treatment-plans/{plan}'],
            ['POST', '/api/treatment-plans/{plan}/items'],
            ['PUT', '/api/treatment-plans/{plan}/items/{item}'],
            ['PATCH', '/api/treatment-plans/{plan}/items/{item}'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_authentication_and_authorization_are_required(string $method, string $path): void
    {
        $item = TreatmentPlanItem::factory()->create();
        $path = str_replace(['{patient}', '{plan}', '{item}'], [$item->treatmentPlan->patient_id, $item->treatment_plan_id, $item->id], $path);
        $this->json($method, $path)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->actor(null);
        $this->json($method, $path)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_create_multiple_items_with_optional_context_snapshot_and_standard_envelope(): void
    {
        $actor = $this->actor();
        $patient = Patient::factory()->create();
        $first = $this->itemPayload();
        $first['tooth_context'] = ['dentition' => 'adult', 'tooth' => 3, 'surfaces' => ['M', 'O', 'F']];
        $first['sequence'] = 2;
        $first['priority'] = 'urgent';
        $second = $this->itemPayload();
        $second['sequence'] = 1;
        $second['estimated_price'] = '99.00';
        $plan = $this->postJson("/api/patients/{$patient->id}/treatment-plans", [
            'title' => 'Initial care', 'notes' => 'Discuss options', 'items' => [$first, $second],
        ])->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId'])
            ->assertJsonPath('success', true)->assertJsonPath('statusCode', 201)
            ->assertJsonPath('data.treatmentPlan.status', 'draft')
            ->assertJsonPath('data.treatmentPlan.patient_id', $patient->id)
            ->assertJsonCount(2, 'data.treatmentPlan.items')
            ->assertJsonPath('data.treatmentPlan.items.0.tooth_context', null)
            ->assertJsonPath('data.treatmentPlan.items.0.estimated_price', '99.00')
            ->assertJsonPath('data.treatmentPlan.items.1.catalog_price', '125.50')
            ->assertJsonPath('data.treatmentPlan.items.1.estimated_price', '125.50')
            ->assertJsonPath('data.treatmentPlan.items.1.tooth_context.tooth', '16')
            ->assertJsonPath('data.treatmentPlan.items.1.tooth_context.numbering_system', 'fdi')
            ->assertJsonPath('data.treatmentPlan.items.1.tooth_context.surfaces', ['M', 'O', 'B'])
            ->json('data.treatmentPlan');
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'treatment_plans', 'resource_id' => (string) $plan['id'], 'actor_id' => $actor->id]);
        $this->assertStringNotContainsString('Discuss options', AuditLog::all()->toJson());
    }

    public function test_snapshot_survives_catalog_changes_and_estimate_provider_context_and_order_can_be_updated(): void
    {
        $this->actor();
        $plan = TreatmentPlan::factory()->create();
        $payload = $this->itemPayload();
        $id = $this->postJson($this->url($plan, '/items'), $payload)->assertCreated()->json('data.treatmentPlanItem.id');
        Treatment::findOrFail($payload['treatment_id'])->update(['price' => '300.00', 'name' => 'New catalog name', 'active' => false]);
        $provider = Dentist::factory()->create();
        $this->patchJson($this->url($plan, '/items/'.$id), [
            'estimated_price' => 0, 'dentist_id' => $provider->id, 'sequence' => 4, 'priority' => 'high',
            'tooth_context' => ['dentition' => 'primary', 'tooth' => 'E', 'surfaces' => ['I', 'P']],
        ])->assertOk()->assertJsonPath('data.treatmentPlanItem.catalog_price', '125.50')
            ->assertJsonPath('data.treatmentPlanItem.treatment_name', 'Cleaning')
            ->assertJsonPath('data.treatmentPlanItem.estimated_price', '0.00')
            ->assertJsonPath('data.treatmentPlanItem.dentist_id', $provider->id)
            ->assertJsonPath('data.treatmentPlanItem.sequence', 4)
            ->assertJsonPath('data.treatmentPlanItem.priority', 'high')
            ->assertJsonPath('data.treatmentPlanItem.tooth_context.tooth', '51');
        $this->putJson($this->url($plan, '/items/'.$id), ['tooth_context' => null])->assertOk()
            ->assertJsonPath('data.treatmentPlanItem.tooth_context', null);
        $count = AuditLog::count();
        $this->patchJson($this->url($plan, '/items/'.$id), [])->assertOk();
        $this->patchJson($this->url($plan), [])->assertOk();
        $this->assertSame($count, AuditLog::count());
    }

    public function test_lifecycle_requires_items_activation_and_finished_work_and_terminal_records_are_read_only(): void
    {
        $this->actor();
        $plan = TreatmentPlan::factory()->create();
        $this->patchJson($this->url($plan), ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'active'])->assertUnprocessable();
        $item = TreatmentPlanItem::factory()->create(['treatment_plan_id' => $plan->id]);
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'active'])->assertOk();
        $this->patchJson($this->url($plan), ['status' => 'draft'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['status' => 'in_progress'])->assertOk();
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['status' => 'proposed'])->assertUnprocessable();
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['status' => 'completed'])->assertOk();
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['notes' => 'change'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['title' => 'Final care'])->assertOk();
        $this->patchJson($this->url($plan), ['status' => 'completed'])->assertOk();
        $count = AuditLog::count();
        $this->postJson($this->url($plan, '/items'), $this->itemPayload())->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'active'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['notes' => 'late edit'])->assertUnprocessable();
        $this->assertSame($count, AuditLog::count());
    }

    public function test_cancellation_preserves_finished_items_and_audits_each_unfinished_item(): void
    {
        $actor = $this->actor();
        $plan = TreatmentPlan::factory()->create(['status' => 'active']);
        $done = TreatmentPlanItem::factory()->create(['treatment_plan_id' => $plan->id, 'status' => 'completed']);
        $open = TreatmentPlanItem::factory()->create(['treatment_plan_id' => $plan->id, 'status' => 'in_progress']);
        $proposed = TreatmentPlanItem::factory()->create(['treatment_plan_id' => $plan->id]);
        $this->patchJson($this->url($plan), ['status' => 'cancelled'])->assertOk();
        $this->assertSame('completed', $done->refresh()->status);
        $this->assertSame('cancelled', $open->refresh()->status);
        $this->assertSame('cancelled', $proposed->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'treatment_plan_items', 'resource_id' => (string) $open->id, 'actor_id' => $actor->id]);
        $this->patchJson($this->url($plan, '/items/'.$open->id), ['status' => 'proposed'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'draft'])->assertUnprocessable();
    }

    public function test_draft_items_can_be_cancelled_but_an_all_cancelled_plan_cannot_be_completed(): void
    {
        $this->actor();
        $item = TreatmentPlanItem::factory()->create();
        $plan = $item->treatmentPlan;
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['status' => 'cancelled'])->assertOk();
        $this->patchJson($this->url($plan), ['status' => 'active'])->assertUnprocessable();
        $plan->update(['status' => 'active']);
        $this->patchJson($this->url($plan), ['status' => 'completed'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['status' => 'cancelled'])->assertOk();
    }

    public static function invalidItems(): array
    {
        return [
            ['estimated_price', -1], ['estimated_price', '1.001'], ['estimated_price', '100000000.00'],
            ['estimated_price', null], ['sequence', 0], ['sequence', 1.5], ['sequence', 1000001],
            ['priority', 'critical'], ['status', 'completed'], ['catalog_price', 1], ['treatment_name', 'Fake'],
            ['treatment_plan_id', 1], ['dentist_id', null], ['treatment_id', 99999], ['notes', ['bad']],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => 33]],
            ['tooth_context', ['dentition' => 'primary', 'tooth' => 'Z']],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => 8, 'surfaces' => ['O']]],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => 3, 'surfaces' => ['I']]],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => 3, 'surfaces' => ['F', 'B']]],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => 3, 'surfaces' => ['X']]],
            ['tooth_context', ['dentition' => 'mixed', 'tooth' => 3]],
            ['tooth_context', ['dentition' => 'adult', 'tooth' => ['bad']]],
            ['tooth_context', ['dentition' => 'adult']], ['tooth_context', []],
        ];
    }

    #[DataProvider('invalidItems')]
    public function test_invalid_items_do_not_create_plans_items_or_audits(string $field, mixed $value): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $this->postJson("/api/patients/{$patient->id}/treatment-plans", [
            'title' => 'Care', 'items' => [array_replace($this->itemPayload(), [$field => $value])],
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertDatabaseCount('treatment_plans', 0);
        $this->assertDatabaseCount('treatment_plan_items', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_invalid_plan_fields_and_item_updates_are_rejected(): void
    {
        $this->actor();
        $item = TreatmentPlanItem::factory()->create();
        foreach ([['title' => ' '], ['status' => 'unknown'], ['patient_id' => $item->treatmentPlan->patient_id], ['items' => []]] as $data) {
            $this->patchJson($this->url($item->treatmentPlan), $data)->assertUnprocessable();
        }
        foreach ([['treatment_id' => $item->treatment_id], ['catalog_price' => 1], ['sequence' => -1], ['estimated_price' => '1.001'], ['priority' => null]] as $data) {
            $this->patchJson($this->url($item->treatmentPlan, '/items/'.$item->id), $data)->assertUnprocessable();
        }
        $this->postJson('/api/patients/'.$item->treatmentPlan->patient_id.'/treatment-plans', [])->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_inactive_treatments_and_invalid_later_items_roll_back_the_entire_batch(): void
    {
        $this->actor();
        $patient = Patient::factory()->create();
        $inactive = Treatment::factory()->create(['active' => false]);
        $this->postJson("/api/patients/{$patient->id}/treatment-plans", [
            'title' => 'Care', 'items' => [$this->itemPayload(), array_replace($this->itemPayload(), ['treatment_id' => $inactive->id])],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.1.treatment_id');
        $this->assertDatabaseCount('treatment_plans', 0);
        $this->assertDatabaseCount('treatment_plan_items', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_read_permission_allows_paginated_filtered_reads_but_no_clinical_writes(): void
    {
        $this->actor(null, 'treatment_plans.view');
        $patient = Patient::factory()->create();
        $first = TreatmentPlan::factory()->create(['patient_id' => $patient->id]);
        $last = TreatmentPlan::factory()->create(['patient_id' => $patient->id]);
        TreatmentPlan::factory()->create(['patient_id' => $patient->id, 'status' => 'cancelled']);
        TreatmentPlan::factory()->create();
        $this->getJson("/api/patients/{$patient->id}/treatment-plans?status=draft&per_page=1")->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.hasMorePages', true)
            ->assertJsonPath('data.0.id', $last->id);
        $this->getJson("/api/patients/{$patient->id}/treatment-plans?status=draft&per_page=1&page=2")->assertOk()
            ->assertJsonPath('data.0.id', $first->id);
        $this->getJson($this->url($first))->assertOk();
        $this->postJson("/api/patients/{$patient->id}/treatment-plans", ['title' => 'Care'])->assertForbidden();
        $this->patchJson($this->url($first), ['title' => 'Changed'])->assertForbidden();
        $this->getJson("/api/patients/{$patient->id}/treatment-plans?status=bad&per_page=101&page=0")->assertUnprocessable();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_permission_wildcards_and_mixed_receptionist_role_do_not_bypass_clinical_writes(): void
    {
        $actor = $this->actor(Role::RECEPTIONIST, '*');
        $patient = Patient::factory()->create();
        $url = "/api/patients/{$patient->id}/treatment-plans";
        $this->postJson($url, ['title' => 'Care'])->assertForbidden();
        $dentist = Role::create(['slug' => Role::DENTIST, 'name' => 'Dentist']);
        $actor->roles()->attach($dentist);
        $this->postJson($url, ['title' => 'Care'])->assertForbidden();
        $admin = Role::create(['slug' => Role::ADMIN, 'name' => 'Admin']);
        $actor->roles()->attach($admin);
        $this->postJson($url, ['title' => 'Care'])->assertCreated();
    }

    public function test_tenant_routes_references_and_mismatched_parent_items_are_isolated(): void
    {
        $this->actor();
        $tenant = Tenant::factory()->create();
        [$foreignPlan, $foreignItem, $foreignTreatment, $foreignDentist] = app(TenantContext::class)->run($tenant, function () {
            $item = TreatmentPlanItem::factory()->create();

            return [$item->treatmentPlan, $item, $item->treatment_id, $item->dentist_id];
        });
        $local = TreatmentPlanItem::factory()->create();
        $this->getJson($this->url($foreignPlan))->assertNotFound();
        $this->patchJson($this->url($foreignPlan), ['title' => 'Changed'])->assertNotFound();
        $this->postJson("/api/patients/{$foreignPlan->patient_id}/treatment-plans", ['title' => 'Care'])->assertNotFound();
        $this->getJson("/api/patients/{$foreignPlan->patient_id}/treatment-plans")->assertNotFound();
        $this->patchJson($this->url($local->treatmentPlan, '/items/'.$foreignItem->id), [])->assertNotFound();
        $otherLocal = TreatmentPlanItem::factory()->create();
        $this->patchJson($this->url($local->treatmentPlan, '/items/'.$otherLocal->id), [])->assertNotFound();
        foreach (['treatment_id' => $foreignTreatment, 'dentist_id' => $foreignDentist] as $field => $value) {
            $this->postJson($this->url($local->treatmentPlan, '/items'), array_replace($this->itemPayload(), [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->getJson($this->url($local->treatmentPlan), ['X-Tenant-Id' => $tenant->id])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_archived_patients_are_readable_but_read_only_and_provider_deletion_preserves_history(): void
    {
        $this->actor(Role::ADMIN);
        $item = TreatmentPlanItem::factory()->create();
        $plan = $item->treatmentPlan;
        $plan->patient->archive();
        $this->getJson($this->url($plan))->assertOk();
        $this->postJson("/api/patients/{$plan->patient_id}/treatment-plans", ['title' => 'Care'])->assertUnprocessable();
        $this->patchJson($this->url($plan), ['title' => 'Changed'])->assertUnprocessable();
        $this->postJson($this->url($plan, '/items'), $this->itemPayload())->assertUnprocessable();
        $this->patchJson($this->url($plan, '/items/'.$item->id), ['notes' => 'Changed'])->assertUnprocessable();
        $this->deleteJson('/api/dentists/'.$item->dentist_id)->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_audit_failure_rolls_back_item_and_plan_changes(): void
    {
        $actor = $this->actor();
        $item = TreatmentPlanItem::factory()->create();
        $audit = Mockery::mock(AuditLogService::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $workflow = new TreatmentPlanWorkflow($audit);
        try {
            $workflow->update($item->treatmentPlan, ['status' => 'cancelled'], $actor);
            $this->fail('Audit failure should abort cancellation.');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertSame('draft', $item->treatmentPlan->refresh()->status);
        $this->assertSame('proposed', $item->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_workflow_enforces_authorization_for_direct_callers(): void
    {
        $actor = $this->actor(Role::RECEPTIONIST, '*');
        $this->expectException(AuthorizationException::class);
        app(TreatmentPlanWorkflow::class)->create(Patient::factory()->create(), ['title' => 'Care'], $actor);
    }

    public function test_database_guards_preserve_ownership_and_snapshots(): void
    {
        $item = TreatmentPlanItem::factory()->create();
        $tenant = Tenant::factory()->create();
        $foreign = app(TenantContext::class)->run($tenant, fn () => Dentist::factory()->create());
        foreach ([['catalog_price' => 1], ['treatment_name' => 'Changed'], ['tenant_id' => $tenant->id], ['dentist_id' => $foreign->id]] as $data) {
            try {
                DB::table('treatment_plan_items')->where('id', $item->id)->update($data);
                $this->fail('Expected database integrity guard.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('Invalid treatment plan ownership or snapshot', $e->getMessage());
            }
        }
        $migration = require database_path('migrations/2026_10_24_000001_create_treatment_plans.php');
        try {
            $migration->down();
            $this->fail('Populated history must be preserved.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('history', $e->getMessage());
        }
        $this->assertDatabaseCount('treatment_plan_items', 1);
    }

    private function itemPayload(): array
    {
        return [
            'treatment_id' => Treatment::factory()->create(['name' => 'Cleaning', 'price' => '125.50'])->id,
            'dentist_id' => Dentist::factory()->create()->id,
        ];
    }

    private function url(TreatmentPlan $plan, string $suffix = ''): string
    {
        return '/api/treatment-plans/'.$plan->id.$suffix;
    }

    private function actor(?string $role = Role::DENTIST, ?string $permission = null): User
    {
        $user = User::factory()->create();
        if ($role !== null || $permission !== null) {
            $roleModel = Role::firstOrCreate(['slug' => $role ?? 'custom'], ['name' => $role ?? 'Custom']);
            $user->roles()->attach($roleModel);
            if ($permission !== null) {
                [$resource, $action] = array_pad(explode('.', $permission, 2), 2, null);
                $grant = Permission::firstOrCreate(['slug' => $permission], ['name' => $permission, 'resource' => $resource, 'action' => $action]);
                $roleModel->permissions()->attach($grant);
            }
        }
        Sanctum::actingAs($user);

        return $user;
    }
}
