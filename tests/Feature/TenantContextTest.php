<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveTenant;
use App\Models\Patient;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_is_available_before_bindings_and_policies_for_bearer_authentication(): void
    {
        $tenant = Tenant::factory()->create();
        $context = app(TenantContext::class);
        [$user, $patient] = $context->run($tenant, function (): array {
            $user = User::factory()->create();
            $role = Role::create(['slug' => Role::ADMIN, 'name' => 'Clinic Admin']);
            $user->roles()->attach($role);

            return [$user, Patient::factory()->create()];
        });
        $token = $user->createToken('tenant-test')->plainTextToken;

        Route::middleware(['api', 'auth:sanctum', 'tenant'])->get('/api/tenant-probe/{patient}',
            function (Patient $patient, TenantContext $context) {
                Gate::authorize('view', $patient);

                return response()->json(['tenant_id' => $context->requireId(), 'patient_id' => $patient->id]);
            });

        $this->withToken($token)->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/tenant-probe/'.$patient->id)->assertOk()
            ->assertHeader('X-Tenant-Id', (string) $tenant->id)
            ->assertExactJson(['tenant_id' => $tenant->id, 'patient_id' => $patient->id]);

        $this->expectException(LogicException::class);
        $context->requireId();
    }

    public function test_authentication_precedes_tenant_header_validation(): void
    {
        $this->withHeader('X-Tenant-Id', 'invalid')->getJson('/api/user')->assertUnauthorized()
            ->assertHeaderMissing('X-Tenant-Id')->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_malformed_headers_never_establish_context(): void
    {
        Sanctum::actingAs(User::factory()->create());
        foreach (['', '0', '01', '-1', '+1', '1.5', '1e2', '1,2', str_repeat('9', 19)] as $invalid) {
            $this->getJson('/api/user', ['X-Tenant-Id' => $invalid])->assertUnprocessable()
                ->assertHeaderMissing('X-Tenant-Id')->assertJsonValidationErrors('tenant_id');
        }
        $this->expectException(LogicException::class);
        app(TenantContext::class)->requireId();
    }

    public function test_session_authenticated_user_receives_verified_tenant_context(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        $this->actingAs($user, 'web')->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->getJson('/api/user')->assertOk()->assertJsonPath('data.current_tenant_id', $tenant->id);
    }

    public function test_missing_nonmember_inactive_and_deleted_clinics_share_a_safe_failure_response(): void
    {
        $user = User::factory()->create();
        $foreign = Tenant::factory()->create(['name' => 'Confidential clinic', 'slug' => 'confidential-clinic']);
        $inactive = Tenant::factory()->create(['active' => false]);
        $deleted = Tenant::factory()->create();
        $deletedId = $deleted->id;
        $deleted->delete();
        $user->tenants()->attach($inactive);
        $user->load('tenants');
        $user->tenants()->detach();
        Sanctum::actingAs($user);

        $expected = null;
        foreach ([null, $foreign->id, $inactive->id, $deletedId, 999999] as $tenantId) {
            $headers = $tenantId === null ? [] : ['X-Tenant-Id' => (string) $tenantId];
            $response = $this->getJson('/api/user', $headers)->assertForbidden()
                ->assertHeaderMissing('X-Tenant-Id')->assertJsonPath('data', null)
                ->assertDontSee('Confidential clinic')->assertDontSee('confidential-clinic');
            $body = $response->json();
            unset($body['requestId']);
            $expected ??= $body;
            $this->assertSame($expected, $body);
        }
    }

    public function test_an_inactive_member_clinic_is_rechecked_on_each_request(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $tenant->id])->assertOk();
        $tenant->update(['active' => false]);
        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $tenant->id])->assertForbidden();
    }

    public function test_single_membership_is_selected_even_when_the_default_clinic_is_missing(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();
        Tenant::where('slug', Tenant::DEFAULT_SLUG)->update(['slug' => 'former-default']);
        Sanctum::actingAs($user);
        $this->getJson('/api/user')->assertOk()->assertHeader('X-Tenant-Id', (string) $tenant->id)
            ->assertJsonPath('data.current_tenant_id', $tenant->id);
        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $tenant->id])->assertOk();
    }

    public function test_multiple_active_memberships_require_an_explicit_clinic_selection(): void
    {
        $user = User::factory()->create();
        $other = Tenant::factory()->create();
        $user->tenants()->attach($other);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertUnprocessable()
            ->assertHeaderMissing('X-Tenant-Id')->assertJsonValidationErrors('tenant_id')
            ->assertJsonPath('errors.tenant_id.0', 'A clinic must be selected for this account.');
        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $other->id])->assertOk()
            ->assertHeader('X-Tenant-Id', (string) $other->id)
            ->assertJsonPath('data.current_tenant_id', $other->id);

        $this->expectException(LogicException::class);
        app(TenantContext::class)->requireId();
    }

    public function test_automatic_selection_ignores_inactive_memberships_and_rechecks_loaded_memberships(): void
    {
        $user = User::factory()->create();
        $inactive = Tenant::factory()->create(['active' => false]);
        $user->tenants()->attach($inactive);
        $user->load('tenants');
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('data.current_tenant_id', $default->id);
        $default->update(['active' => false]);
        $this->getJson('/api/user')->assertForbidden()->assertHeaderMissing('X-Tenant-Id');
    }

    public function test_ownership_fields_in_query_and_body_cannot_override_context(): void
    {
        $user = User::factory()->create();
        $foreign = Tenant::factory()->create();
        Sanctum::actingAs($user);
        foreach (['tenant_id', 'tenantId'] as $field) {
            $this->getJson('/api/user?'.$field.'='.$foreign->id)->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
            $this->postJson('/api/patients', [$field => $foreign->id])->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
            $this->postJson('/api/patients', [$field => null])->assertUnprocessable()
                ->assertJsonValidationErrors('tenant_id');
        }
        $this->assertDatabaseCount('patient', 0);
    }

    public function test_loaded_memberships_and_previous_roles_do_not_leak_when_switching_clinics(): void
    {
        $default = Tenant::where('slug', Tenant::DEFAULT_SLUG)->sole();
        $other = Tenant::factory()->create(['name' => 'Private clinic', 'slug' => 'private-clinic']);
        $user = User::factory()->create();
        $user->tenants()->attach($other);
        $context = app(TenantContext::class);
        $context->run($other, function () use ($user): void {
            $role = Role::create(['slug' => 'private-role', 'name' => 'Private clinic role']);
            $user->roles()->attach($role);
            $user->load('roles.permissions');
        });
        $user->load('tenants');
        Sanctum::actingAs($user);

        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $other->id])->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'private-role')->assertJsonMissingPath('data.tenants');
        $this->getJson('/api/user', ['X-Tenant-Id' => (string) $default->id])->assertOk()->assertHeader('X-Tenant-Id', (string) $default->id)
            ->assertJsonPath('data.current_tenant_id', $default->id)->assertJsonPath('data.roles', [])
            ->assertJsonMissingPath('data.tenants')->assertDontSee('private-clinic')->assertDontSee('private-role');
        $this->assertFalse($user->relationLoaded('roles'));
    }

    public function test_nested_context_restores_the_previous_tenant_on_success_and_exception(): void
    {
        $outer = Tenant::factory()->create();
        $inner = Tenant::factory()->create();
        $context = app(TenantContext::class);
        $context->run($outer, function () use ($context, $outer, $inner): void {
            $this->assertSame($inner->id, $context->run($inner, fn () => $context->requireId()));
            $this->assertSame($outer->id, $context->requireId());
            try {
                $context->run($inner, fn () => throw new RuntimeException('Job failed'));
                $this->fail('Expected the callback exception.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Job failed', $exception->getMessage());
            }
            $this->assertSame($outer->id, $context->requireId());
        });
        $this->expectException(LogicException::class);
        $context->requireId();
    }

    public function test_middleware_cleans_up_context_and_cached_roles_after_downstream_failure(): void
    {
        $outer = Tenant::factory()->create();
        $inner = Tenant::factory()->create();
        $user = User::factory()->forTenant($inner)->create();
        $request = Request::create('/api/probe');
        $request->headers->set('X-Tenant-Id', (string) $inner->id);
        $request->setUserResolver(fn () => $user);
        $context = app(TenantContext::class);
        $context->run($outer, function () use ($context, $request, $user, $outer, $inner): void {
            try {
                app(ResolveTenant::class)->handle($request, function () use ($context, $user, $inner) {
                    $this->assertSame($inner->id, $context->requireId());
                    $user->load('roles');
                    throw new RuntimeException('Controller failed');
                });
                $this->fail('Expected the downstream exception.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Controller failed', $exception->getMessage());
            }
            $this->assertSame($outer->id, $context->requireId());
            $this->assertFalse($request->attributes->has('tenant_id'));
            $this->assertFalse($user->relationLoaded('roles'));
        });
    }

    public function test_unpersisted_tenant_cannot_silently_use_the_default_context(): void
    {
        $this->expectException(LogicException::class);
        app(TenantContext::class)->run(new Tenant, fn () => $this->fail('Context must not be entered.'));
    }

    public function test_legacy_default_is_not_an_explicit_context_for_dispatching_jobs(): void
    {
        $context = app(TenantContext::class);
        $this->assertSame(Tenant::where('slug', Tenant::DEFAULT_SLUG)->value('id'), $context->id());
        $this->expectException(LogicException::class);
        $context->requireId();
    }
}
