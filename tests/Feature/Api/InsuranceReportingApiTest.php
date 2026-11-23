<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsuranceReportingApiTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = ['date_from' => '2026-10-26', 'date_to' => '2026-10-27'];

    public function test_exact_permission_and_tenant_membership_are_required(): void
    {
        $this->getJson($this->url())->assertUnauthorized();

        $this->actor(['patients.view', 'reports.*']);
        $this->getJson($this->url())->assertForbidden();

        $this->actor(['patients.view', '*.view']);
        $this->getJson($this->url())->assertForbidden();

        $this->actor(['patients.view', 'insurance_reports.view']);
        $this->getJson($this->url())->assertOk();
    }

    public function test_report_is_aggregate_only_audited_and_excludes_foreign_tenant_rows(): void
    {
        $actor = $this->actor(['patients.view', 'insurance_reports.view']);
        $foreignTenant = Tenant::factory()->create();
        app(TenantContext::class)->run($foreignTenant, function () use ($foreignTenant) {
            $foreignActor = User::factory()->forTenant($foreignTenant)->create();
            $patient = \App\Models\Patient::factory()->create();

            DB::table('insurance_estimates')->insert([
                'tenant_id' => app(TenantContext::class)->requireId(),
                'patient_id' => $patient->id,
                'service_date' => '2026-10-26',
                'treatment_description' => 'Private treatment description',
                'gross_amount' => '120.00',
                'currency' => 'USD',
                'status' => 'resolved',
                'insurer_amount' => '80.00',
                'patient_amount' => '40.00',
                'calculation' => '{}',
                'created_by_user_id' => $foreignActor->id,
                'recorded_at' => '2026-10-26 12:00:00',
            ]);

        });

        $response = $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.estimates.by_status.resolved', 0)
            ->assertJsonPath('data.estimates.by_status.unresolved', 0)
            ->assertJsonPath('data.insurer_receipts.recorded_count', 0)
            ->assertJsonPath('data.insurer_obligations.proposed_count', 0)
            ->assertJsonPath('meta.filters.date_from', self::FILTERS['date_from'])
            ->assertJsonPath('meta.filters.date_to', self::FILTERS['date_to'])
            ->assertHeader('Cache-Control', 'no-store, private');

        foreach (['patient_id', 'patient_name', 'treatment_description', 'insurer_amount', 'patient_amount', 'amount_paid', 'balance'] as $privateOrFinancialField) {
            $this->assertArrayNotHasKey($privateOrFinancialField, $response->json('data'));
            $this->assertStringNotContainsString($privateOrFinancialField, $response->getContent());
        }
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => app(TenantContext::class)->id(),
            'actor_id' => $actor->id,
            'action' => 'insurance_report.viewed',
            'resource_type' => 'insurance_reports',
            'resource_id' => 'summary',
        ]);
    }

    public function test_date_window_is_validated_and_authorization_precedes_validation(): void
    {
        $this->actor(['patients.view']);
        $this->getJson('/api/reports/insurance?date_from=invalid&date_to=invalid')->assertForbidden();

        $this->actor(['patients.view', 'insurance_reports.view']);
        $this->getJson('/api/reports/insurance?date_from=2026-10-27&date_to=2026-10-26')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson('/api/reports/insurance?date_from=2025-10-26&date_to=2026-10-27')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    private function actor(array $grants): User
    {
        $actor = User::factory()->create();
        $role = Role::create(['slug' => 'insurance-report-'.$actor->id, 'name' => 'Insurance report reader']);
        foreach ($grants as $grant) {
            [$resource, $action] = explode('.', $grant, 2);
            $permission = Permission::firstOrCreate(['slug' => $grant], ['name' => $grant, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function url(): string
    {
        return '/api/reports/insurance?'.http_build_query(self::FILTERS);
    }
}
