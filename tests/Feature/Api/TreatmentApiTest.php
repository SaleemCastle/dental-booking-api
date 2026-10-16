<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TreatmentApiTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endpoints')]
    public function test_endpoints_require_authentication(string $method, string $path, string $permission, int $status): void
    {
        $treatment = Treatment::factory()->create();

        $this->json($method, str_replace('{id}', $treatment->id, $path), $this->payload())
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    #[DataProvider('endpoints')]
    public function test_endpoints_deny_users_without_permission(string $method, string $path, string $permission, int $status): void
    {
        Sanctum::actingAs(User::factory()->create());
        $treatment = Treatment::factory()->create();
        $original = $treatment->refresh()->getAttributes();

        $this->json($method, str_replace('{id}', $treatment->id, $path))
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertDatabaseCount('treatments', 1);
        $this->assertSame($original, $treatment->refresh()->getAttributes());
    }

    #[DataProvider('endpoints')]
    public function test_each_endpoint_accepts_its_resource_permission(string $method, string $path, string $permission, int $status): void
    {
        $this->actingAsUserWithPermissions($permission);
        $treatment = Treatment::factory()->create();

        $this->json($method, str_replace('{id}', $treatment->id, $path), $this->payload())
            ->assertStatus($status);
    }

    public static function endpoints(): array
    {
        return [
            'list' => ['GET', '/api/treatments', 'treatments.view', 200],
            'selector' => ['GET', '/api/treatments?active=1', 'treatments.view', 200],
            'create' => ['POST', '/api/treatments', 'treatments.create', 201],
            'show' => ['GET', '/api/treatments/{id}', 'treatments.view', 200],
            'patch' => ['PATCH', '/api/treatments/{id}', 'treatments.update', 200],
            'put' => ['PUT', '/api/treatments/{id}', 'treatments.update', 200],
            'delete' => ['DELETE', '/api/treatments/{id}', 'treatments.delete', 200],
        ];
    }

    public function test_read_permission_does_not_allow_catalog_changes(): void
    {
        $this->actingAsUserWithPermissions('treatments.view');
        $treatment = Treatment::factory()->create();
        $original = $treatment->refresh()->getAttributes();

        $this->postJson('/api/treatments', $this->payload())->assertForbidden();
        $this->patchJson("/api/treatments/{$treatment->id}", $this->payload())->assertForbidden();
        $this->putJson("/api/treatments/{$treatment->id}", $this->payload())->assertForbidden();
        $this->deleteJson("/api/treatments/{$treatment->id}")->assertForbidden();

        $this->assertDatabaseCount('treatments', 1);
        $this->assertSame($original, $treatment->refresh()->getAttributes());
    }

    public function test_admin_can_manage_treatments_without_explicit_permissions(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => Role::ADMIN]);
        $user->roles()->attach($role);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/treatments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 201)
            ->assertJsonPath('data.treatment.name', 'Cleaning')
            ->assertJsonPath('data.treatment.description', 'Routine dental cleaning')
            ->assertJsonPath('data.treatment.category', 'Preventive')
            ->assertJsonPath('data.treatment.duration_minutes', 30)
            ->assertJsonPath('data.treatment.price', '125.50')
            ->assertJsonPath('data.treatment.active', true)
            ->assertJsonStructure(['requestId', 'data' => ['treatment' => ['id', 'created_at', 'updated_at']]]);

        $treatmentId = $response->json('data.treatment.id');

        $this->getJson('/api/treatments')->assertOk()->assertJsonCount(1, 'data.treatments');
        $this->getJson("/api/treatments/{$treatmentId}")->assertOk()->assertJsonPath('data.treatment.id', $treatmentId);
        $this->patchJson("/api/treatments/{$treatmentId}", [
            'name' => 'Deep cleaning',
            'description' => null,
            'category' => 'Periodontal',
            'duration_minutes' => 60,
            'price' => '250.00',
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.treatment.name', 'Deep cleaning')
            ->assertJsonPath('data.treatment.description', null)
            ->assertJsonPath('data.treatment.category', 'Periodontal')
            ->assertJsonPath('data.treatment.duration_minutes', 60)
            ->assertJsonPath('data.treatment.price', '250.00')
            ->assertJsonPath('data.treatment.active', false);

        $this->putJson("/api/treatments/{$treatmentId}", ['active' => true])
            ->assertOk()
            ->assertJsonPath('data.treatment.active', true)
            ->assertJsonPath('data.treatment.category', 'Periodontal');

        $this->deleteJson("/api/treatments/{$treatmentId}")->assertOk();
        $this->assertDatabaseHas('treatments', ['id' => $treatmentId, 'active' => false]);
    }

    public function test_catalog_can_filter_both_active_and_inactive_treatments(): void
    {
        $this->actingAsUserWithPermissions('treatments.view');
        $inactive = Treatment::factory()->create(['name' => 'Archived', 'active' => false]);
        $last = Treatment::factory()->create(['name' => 'Whitening']);
        $first = Treatment::factory()->create(['name' => 'Cleaning']);

        $this->getJson('/api/treatments')
            ->assertOk()
            ->assertJsonCount(3, 'data.treatments')
            ->assertJsonPath('data.treatments.0.id', $inactive->id);

        $this->getJson('/api/treatments?active=1')
            ->assertOk()
            ->assertJsonCount(2, 'data.treatments')
            ->assertJsonPath('data.treatments.0.id', $first->id)
            ->assertJsonPath('data.treatments.0.active', true)
            ->assertJsonPath('data.treatments.1.id', $last->id);

        $this->getJson('/api/treatments?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.treatments')
            ->assertJsonPath('data.treatments.0.id', $inactive->id)
            ->assertJsonPath('data.treatments.0.active', false);
    }

    public function test_active_selector_returns_an_empty_list_when_no_treatments_are_active(): void
    {
        $this->actingAsUserWithPermissions('treatments.view');
        Treatment::factory()->create(['active' => false]);

        $this->getJson('/api/treatments?active=1')->assertOk()->assertJsonPath('data.treatments', []);
    }

    public function test_active_filter_is_validated(): void
    {
        $this->actingAsUserWithPermissions('treatments.view');

        foreach (['all', '2', '', 'true', 'false', '1&active[]=0'] as $value) {
            $this->getJson('/api/treatments?active='.$value)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('active');
        }
    }

    public function test_deactivation_preserves_historical_appointment_treatment_details(): void
    {
        $this->actingAsUserWithPermissions('treatments.*');
        $treatment = Treatment::factory()->create(['name' => 'Historical cleaning']);
        $appointment = Appointment::factory()->create(['treatment_id' => $treatment->id, 'status' => 'completed']);

        $this->deleteJson("/api/treatments/{$treatment->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Treatment successfully deactivated.');
        $this->deleteJson("/api/treatments/{$treatment->id}")->assertOk();

        $this->assertDatabaseHas('treatments', ['id' => $treatment->id, 'active' => false]);
        $this->assertSame($treatment->id, $appointment->refresh()->treatment_id);
        $this->assertSame('Historical cleaning', $appointment->treatment->name);

        $this->getJson("/api/treatments/{$treatment->id}")->assertOk()->assertJsonPath('data.treatment.active', false);
        $this->getJson("/api/appointments/{$appointment->id}")
            ->assertOk()
            ->assertJsonPath('data.appointment.treatmentName', 'Historical cleaning')
            ->assertJsonPath('data.appointment.treatment.active', false);
        $this->getJson('/api/treatments?active=1')->assertOk()->assertJsonCount(0, 'data.treatments');
    }

    public function test_create_defaults_to_active_and_allows_zero_price_and_no_description(): void
    {
        $this->actingAsUserWithPermissions('treatments.create');
        $payload = $this->payload();
        unset($payload['description']);
        $payload['price'] = 0;
        $payload['duration_minutes'] = 1;

        $this->postJson('/api/treatments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.treatment.description', null)
            ->assertJsonPath('data.treatment.price', '0.00')
            ->assertJsonPath('data.treatment.active', true);
    }

    public function test_create_supports_inactive_treatments_and_upper_numeric_limits(): void
    {
        $this->actingAsUserWithPermissions('treatments.create');

        $this->postJson('/api/treatments', array_replace($this->payload(), [
            'price' => '99999999.99',
            'duration_minutes' => 1440,
            'active' => false,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.treatment.price', '99999999.99')
            ->assertJsonPath('data.treatment.duration_minutes', 1440)
            ->assertJsonPath('data.treatment.active', false);
    }

    public function test_create_requires_catalog_fields(): void
    {
        $this->actingAsUserWithPermissions('treatments.create');

        $this->postJson('/api/treatments', [])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['name', 'category', 'duration_minutes', 'price']);
        $this->assertDatabaseCount('treatments', 0);
    }

    #[DataProvider('invalidFields')]
    public function test_create_and_update_validate_fields(string $field, mixed $value): void
    {
        $this->actingAsUserWithPermissions('treatments.*');
        $treatment = Treatment::factory()->create();
        $original = $treatment->refresh()->getAttributes();

        $this->postJson('/api/treatments', array_replace($this->payload(), [$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        $this->patchJson("/api/treatments/{$treatment->id}", [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('treatments', 1);
        $this->assertSame($original, $treatment->refresh()->getAttributes());
    }

    public static function invalidFields(): array
    {
        return [
            'blank name' => ['name', ' '],
            'long name' => ['name', str_repeat('a', 256)],
            'invalid description' => ['description', ['invalid']],
            'blank category' => ['category', ''],
            'null category' => ['category', null],
            'long category' => ['category', str_repeat('a', 101)],
            'zero duration' => ['duration_minutes', 0],
            'long duration' => ['duration_minutes', 1441],
            'fractional duration' => ['duration_minutes', 1.5],
            'null duration' => ['duration_minutes', null],
            'negative price' => ['price', -1],
            'large price' => ['price', '100000000.00'],
            'fractional cent' => ['price', '1.001'],
            'invalid price' => ['price', 'free'],
            'null price' => ['price', null],
            'invalid active' => ['active', 'yes'],
            'null active' => ['active', null],
        ];
    }

    public function test_missing_treatments_return_not_found(): void
    {
        $this->actingAsUserWithPermissions('treatments.*');

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/treatments/999999', $this->payload())
                ->assertNotFound()
                ->assertJsonPath('code', 'NOT_FOUND');
        }
    }

    public function test_migration_preserves_existing_treatments_with_catalog_defaults(): void
    {
        $migration = require database_path('migrations/2026_10_16_000001_add_catalog_fields_to_treatments_table.php');
        $migration->down();

        $payload = $this->payload();
        unset($payload['category']);
        $treatmentId = DB::table('treatments')->insertGetId($payload);
        $migration->up();

        $this->assertDatabaseHas('treatments', [
            'id' => $treatmentId,
            'name' => 'Cleaning',
            'category' => 'Uncategorized',
            'active' => true,
        ]);
    }

    private function payload(): array
    {
        return [
            'name' => 'Cleaning',
            'description' => 'Routine dental cleaning',
            'category' => 'Preventive',
            'duration_minutes' => 30,
            'price' => '125.50',
        ];
    }

    private function actingAsUserWithPermissions(string $permissionSlug): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Catalog role', 'slug' => 'catalog-role']);
        [$resource, $action] = explode('.', $permissionSlug, 2);
        $permission = Permission::create([
            'name' => $permissionSlug,
            'slug' => $permissionSlug,
            'resource' => $resource,
            'action' => $action,
        ]);
        $role->permissions()->attach($permission);
        $user->roles()->attach($role);

        Sanctum::actingAs($user);
    }
}
