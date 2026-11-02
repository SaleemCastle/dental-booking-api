<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\ProviderAvailability;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Treatment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportingApiTest extends TestCase
{
    use RefreshDatabase;

    private const FILTERS = ['date_from' => '2026-10-26', 'date_to' => '2026-10-27'];

    public static function reports(): array
    {
        return [
            ['appointments', ['appointments.view']],
            ['providers', ['appointments.view']],
            ['treatments', ['appointments.view', 'treatments.view']],
            ['billing', ['invoices.view', 'payments.view']],
        ];
    }

    #[DataProvider('reports')]
    public function test_authentication_and_read_permissions_are_required(string $report, array $grants): void
    {
        $this->getJson($this->url($report))->assertUnauthorized();
        $this->actor(['appointments.create', 'invoices.create', 'payments.create', 'treatments.create']);
        // Authorization precedes validation, including invalid filter IDs.
        $this->getJson($this->url($report, ['dentist_id' => 999999]))->assertForbidden();
        $this->actor(['reports.*']);
        $this->getJson($this->url($report))->assertForbidden();
        $this->actor($grants);
        $this->getJson($this->url($report))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actor(['*.view']);
        $this->getJson($this->url($report))->assertOk();
    }

    public function test_treatments_and_billing_require_both_source_read_permissions(): void
    {
        foreach (['appointments.view', 'treatments.view'] as $grant) {
            $this->actor([$grant]);
            $this->getJson($this->url('treatments'))->assertForbidden();
        }
        foreach (['invoices.view', 'payments.view'] as $grant) {
            $this->actor([$grant]);
            $this->getJson($this->url('billing'))->assertForbidden();
        }
        foreach ([Role::BILLING, Role::AUDITOR] as $role) {
            $this->actor([], $role);
            $this->getJson($this->url('billing'))->assertOk();
            $this->getJson($this->url('appointments'))->assertForbidden();
        }
        $this->actor([], Role::RECEPTIONIST);
        $this->getJson($this->url('billing'))->assertForbidden();
    }

    public function test_volume_counts_every_status_and_honors_dates_and_provider(): void
    {
        $this->actor();
        $provider = Dentist::factory()->create();
        foreach (Appointment::STATUSES as $index => $status) {
            $this->appointment($provider, $status, $index % 2 ? '2026-10-27 23:59:59' : '2026-10-26 00:00:00');
        }
        $this->appointment($provider, 'scheduled', '2026-10-25 23:59:59');
        $this->appointment($provider, 'scheduled', '2026-10-28 00:00:00');
        $this->appointment(Dentist::factory()->create());

        $this->getJson($this->url('appointments', ['dentist_id' => $provider->id]))->assertOk()
            ->assertJsonPath('data.appointment_count', 6)
            ->assertJsonPath('data.by_status', array_fill_keys(Appointment::STATUSES, 1))
            ->assertJsonPath('data.by_date.0.date', '2026-10-26')
            ->assertJsonPath('data.by_date.0.appointment_count', 3)
            ->assertJsonPath('data.by_date.1.date', '2026-10-27')
            ->assertJsonPath('data.by_date.1.appointment_count', 3)
            ->assertJsonPath('meta.filters.dentist_id', $provider->id)->assertJsonPath('meta.timezone', 'UTC');
        $this->getJson($this->url('appointments'))->assertJsonPath('data.appointment_count', 7);
    }

    public function test_treatment_counts_use_completed_appointments_without_plan_or_billing_double_counting(): void
    {
        $this->actor();
        $provider = Dentist::factory()->create();
        $treatment = Treatment::factory()->create(['active' => false]);
        $other = Treatment::factory()->create();
        foreach (['completed', 'completed', 'scheduled', 'cancelled', 'no_show'] as $status) {
            $this->appointment($provider, $status, extra: ['treatment_id' => $treatment->id]);
        }
        $this->appointment($provider, 'completed', extra: ['treatment_id' => null]);
        $this->appointment($provider, 'completed', extra: ['treatment_id' => $other->id]);
        $this->appointment($provider, 'completed', '2026-10-28 00:00:00', extra: ['treatment_id' => $treatment->id]);
        $this->appointment(Dentist::factory()->create(), 'completed', extra: ['treatment_id' => $treatment->id]);

        $this->getJson($this->url('treatments', ['dentist_id' => $provider->id, 'per_page' => 1]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.treatment_id', $treatment->id)
            ->assertJsonPath('data.0.appointment_count', 5)->assertJsonPath('data.0.completed_count', 2)
            ->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.hasMorePages', true);
        $this->getJson($this->url('treatments', ['dentist_id' => $provider->id, 'per_page' => 1, 'page' => 2]))->assertOk()
            ->assertJsonPath('data.0.treatment_id', $other->id)->assertJsonPath('data.0.completed_count', 1);
    }

    public function test_provider_activity_and_capacity_subtract_breaks_and_apply_exceptions(): void
    {
        $this->actor();
        $provider = Dentist::factory()->create();
        ProviderAvailability::create([
            'dentist_id' => $provider->id,
            'weekly_hours' => [
                ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'],
                ['day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '16:00'],
                ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '17:00'],
            ],
            'breaks' => [
                ['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00'],
                ['day_of_week' => 1, 'start_time' => '12:30', 'end_time' => '13:30'],
                ['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00'],
            ],
            'exceptions' => [['date' => '2026-10-27', 'working_hours' => [
                ['start_time' => '10:00', 'end_time' => '12:00'],
            ], 'breaks' => [['start_time' => '11:00', 'end_time' => '11:30']]]],
        ]);
        $this->appointment($provider, 'completed', '2026-10-26 09:00:00', 120);
        $this->appointment($provider, 'scheduled', '2026-10-26 10:00:00', 120); // overlapping historical bookings
        $this->appointment($provider, 'no_show', '2026-10-26 14:00:00', 60);
        $this->appointment($provider, 'cancelled', '2026-10-26 15:00:00', 60);
        $this->appointment($provider, 'in_progress', '2026-10-27 10:00:00', 120);

        $this->getJson($this->url('providers', ['dentist_id' => $provider->id]))->assertOk()
            ->assertJsonPath('data.0.appointment_count', 5)
            ->assertJsonPath('data.0.by_status.completed', 1)
            ->assertJsonPath('data.0.booked_minutes', 360)->assertJsonPath('data.0.completed_minutes', 120)
            ->assertJsonPath('data.0.available_minutes', 480)->assertJsonPath('data.0.occupied_minutes', 270)
            ->assertJsonPath('data.0.utilization_percent', 56.25);
    }

    public function test_utilization_clips_midnight_bookings_and_reports_unknown_and_closed_capacity(): void
    {
        $this->actor();
        $provider = Dentist::factory()->create();
        $schedule = ProviderAvailability::create([
            'dentist_id' => $provider->id, 'weekly_hours' => [], 'breaks' => [],
            'exceptions' => [['date' => '2026-10-26', 'working_hours' => [
                ['start_time' => '00:00', 'end_time' => '01:00'],
                ['start_time' => '23:00', 'end_time' => '24:00'],
            ], 'breaks' => []]],
        ]);
        $this->appointment($provider, 'scheduled', '2026-10-25 23:30:00', 60);
        $this->appointment($provider, 'checked_in', '2026-10-26 23:30:00', 60);
        $filters = ['dentist_id' => $provider->id, 'date_to' => '2026-10-26'];
        $this->getJson($this->url('providers', $filters))->assertOk()
            ->assertJsonPath('data.0.appointment_count', 1)->assertJsonPath('data.0.available_minutes', 120)
            ->assertJsonPath('data.0.occupied_minutes', 60)->assertJsonPath('data.0.utilization_percent', 50);
        $schedule->update(['exceptions' => [['date' => '2026-10-26', 'working_hours' => [], 'breaks' => []]]]);
        $this->getJson($this->url('providers', $filters))->assertJsonPath('data.0.available_minutes', 0)
            ->assertJsonPath('data.0.occupied_minutes', 0)->assertJsonPath('data.0.utilization_percent', null);
        $schedule->delete();
        $this->getJson($this->url('providers', $filters))->assertJsonPath('data.0.available_minutes', null)
            ->assertJsonPath('data.0.occupied_minutes', null)->assertJsonPath('data.0.utilization_percent', null);
    }

    public function test_provider_activity_does_not_disclose_availability_without_its_permission(): void
    {
        $this->actor(['appointments.view']);
        $provider = Dentist::factory()->create();
        ProviderAvailability::create(['dentist_id' => $provider->id, 'weekly_hours' => [], 'breaks' => [], 'exceptions' => []]);
        $this->appointment($provider);
        DB::enableQueryLog();
        $this->getJson($this->url('providers'))->assertOk()->assertJsonPath('data.0.appointment_count', 1)
            ->assertJsonPath('data.0.available_minutes', null)->assertJsonPath('data.0.occupied_minutes', null)
            ->assertJsonPath('data.0.utilization_percent', null);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();
        $this->assertStringNotContainsString('provider_availabilities', $queries);
        $this->assertStringNotContainsString('from "patient"', $queries);
        $this->assertStringNotContainsString('from `patient`', $queries);
    }

    public function test_provider_pagination_includes_providers_with_zero_activity(): void
    {
        $this->actor();
        $first = Dentist::factory()->create();
        $second = Dentist::factory()->create();
        $this->getJson($this->url('providers', ['per_page' => 1]))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.dentist_id', $first->id)
            ->assertJsonPath('data.0.appointment_count', 0)->assertJsonPath('meta.pagination.total', 2);
        $this->getJson($this->url('providers', ['per_page' => 1, 'page' => 2]))->assertOk()
            ->assertJsonPath('data.0.dentist_id', $second->id)->assertJsonPath('meta.pagination.hasMorePages', false);
    }

    public function test_billing_distinguishes_issue_cohort_balance_from_period_collections_and_currencies(): void
    {
        $this->actor();
        $invoice = $this->invoice('100.10');
        Payment::factory()->for($invoice)->create(['amount' => '20.10', 'paid_at' => '2026-10-26 00:00:00']);
        Payment::factory()->for($invoice)->create(['amount' => '30.00', 'paid_at' => '2026-10-28 00:00:00']);
        $older = $this->invoice('200.00', '2026-10-25 23:59:59');
        Payment::factory()->for($older)->create(['amount' => '40.20', 'paid_at' => '2026-10-27 23:59:59']);
        $oldEuro = $this->invoice('1.00', '2026-10-25 00:00:00', 'EUR');
        Payment::factory()->for($oldEuro)->create(['amount' => '0.10', 'currency' => 'EUR', 'paid_at' => '2026-10-26 12:00:00']);
        $this->invoice('0.20', '2026-10-27 23:59:59', 'JMD');
        $this->invoice('0.10', '2026-10-26 00:00:00', 'JMD');
        Invoice::factory()->create(['amount_due' => '999.00']); // draft
        $void = $this->invoice('999.00');
        $void->update(['status' => 'void', 'void_reason' => 'Duplicate', 'voided_at' => now()]);
        $this->invoice('999.00', '2026-10-28 00:00:00');

        $this->getJson($this->url('billing'))->assertOk()->assertExactJson($this->billingEnvelope([
            ['currency' => 'EUR', 'invoice_count' => 0, 'invoiced_total' => '0.00', 'paid_total' => '0.00', 'outstanding_balance' => '0.00', 'payment_count' => 1, 'collections_total' => '0.10'],
            ['currency' => 'JMD', 'invoice_count' => 2, 'invoiced_total' => '0.30', 'paid_total' => '0.00', 'outstanding_balance' => '0.30', 'payment_count' => 0, 'collections_total' => '0.00'],
            ['currency' => 'USD', 'invoice_count' => 1, 'invoiced_total' => '100.10', 'paid_total' => '50.10', 'outstanding_balance' => '50.00', 'payment_count' => 2, 'collections_total' => '60.30'],
        ]));
    }

    public function test_billing_aggregates_can_exceed_the_single_invoice_amount_limit(): void
    {
        $this->actor();
        $this->invoice('99999999.99');
        $this->invoice('99999999.99');
        $this->getJson($this->url('billing'))->assertOk()->assertJsonPath('data.by_currency.0.invoiced_total', '199999999.98')
            ->assertJsonPath('data.by_currency.0.outstanding_balance', '199999999.98');
    }

    public function test_billing_provider_filter_attributes_only_invoices_with_a_matching_appointment(): void
    {
        $this->actor();
        $provider = Dentist::factory()->create();
        $appointment = $this->appointment($provider);
        $invoice = $this->invoice('10.00', appointment: $appointment);
        Payment::factory()->for($invoice)->create(['amount' => '2.00', 'paid_at' => '2026-10-26 12:00:00']);
        $unlinked = $this->invoice('100.00');
        Payment::factory()->for($unlinked)->create(['amount' => '50.00', 'paid_at' => '2026-10-26 12:00:00']);
        $this->invoice('200.00', appointment: $this->appointment(Dentist::factory()->create()));

        $this->getJson($this->url('billing', ['dentist_id' => $provider->id]))->assertOk()
            ->assertJsonPath('data.by_currency.0.invoice_count', 1)->assertJsonPath('data.by_currency.0.invoiced_total', '10.00')
            ->assertJsonPath('data.by_currency.0.outstanding_balance', '8.00')->assertJsonPath('data.by_currency.0.collections_total', '2.00');
    }

    public function test_reports_are_clinic_scoped_and_foreign_filters_and_grants_are_rejected(): void
    {
        $actor = $this->actor();
        $ownProvider = Dentist::factory()->create();
        $this->withHeader('X-Tenant-Id', (string) $ownProvider->tenant_id);
        $this->appointment($ownProvider, 'completed');
        $this->invoice('12.00');
        $tenant = Tenant::factory()->create();
        $foreignProvider = app(TenantContext::class)->run($tenant, function () use ($actor) {
            $actor->tenants()->attach(app(TenantContext::class)->id());
            $provider = Dentist::factory()->create();
            $this->appointment($provider, 'completed');
            $this->invoice('999.00');

            return $provider;
        });
        $this->getJson($this->url('appointments'))->assertJsonPath('data.appointment_count', 1);
        $this->getJson($this->url('providers'))->assertJsonPath('meta.pagination.total', 1);
        $this->getJson($this->url('treatments'))->assertJsonPath('meta.pagination.total', 1);
        $this->getJson($this->url('billing'))->assertJsonPath('data.by_currency.0.invoiced_total', '12.00');
        foreach (self::reports() as [$report]) {
            $this->getJson($this->url($report, ['dentist_id' => $foreignProvider->id]))->assertUnprocessable()->assertJsonValidationErrors('dentist_id');
            // Default-clinic admin membership confers no grants in this other clinic.
            $this->getJson($this->url($report), ['X-Tenant-Id' => (string) $tenant->id])->assertForbidden();
        }
        $actor->tenants()->detach($tenant->id);
        $this->getJson($this->url('appointments'), ['X-Tenant-Id' => (string) $tenant->id])->assertForbidden();
    }

    #[DataProvider('reports')]
    public function test_reports_return_only_aggregate_fields_and_never_patient_or_financial_details(string $report): void
    {
        $this->actor();
        $patient = Patient::factory()->create(['firstName' => 'PrivatePatientName']);
        $appointment = $this->appointment(Dentist::factory()->create(), 'completed', extra: ['patient_id' => $patient->id, 'description' => 'PrivateClinicalText']);
        $invoice = $this->invoice('10.00', appointment: $appointment);
        Payment::factory()->for($invoice)->create(['amount' => '1.00', 'paid_at' => '2026-10-26 12:00:00', 'reference' => 'PrivatePaymentRef']);
        $response = $this->getJson($this->url($report))->assertOk();
        foreach (['patient_id', 'patientName', 'PrivatePatientName', 'PrivateClinicalText', 'PrivatePaymentRef', 'invoice_number', 'receipt_number', 'description', 'tooth_context'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidFilters(): array
    {
        return [
            [['date_from' => null], 'date_from'], [['date_to' => null], 'date_to'],
            [['date_from' => '2026-02-30'], 'date_from'], [['date_from' => 'yesterday'], 'date_from'],
            [['date_to' => '2026-10-25'], 'date_to'], [['date_to' => '2027-10-27'], 'date_to'],
            [['dentist_id' => 999999], 'dentist_id'], [['dentist_id' => null], 'dentist_id'],
            [['per_page' => 101], 'per_page'], [['per_page' => 0], 'per_page'], [['page' => 0], 'page'],
            [['date_from' => ['2026-10-26']], 'date_from'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_validation_errors(array $filters, string $field): void
    {
        $this->actor();
        foreach (self::reports() as [$report]) {
            $this->getJson($this->url($report, $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    public function test_empty_reports_and_a_366_day_range_are_well_defined(): void
    {
        $this->actor();
        $this->getJson($this->url('appointments', ['date_from' => '2025-10-27', 'date_to' => '2026-10-27']))->assertOk()
            ->assertJsonPath('data.appointment_count', 0)->assertJsonPath('data.by_date', [])
            ->assertJsonPath('data.by_status', array_fill_keys(Appointment::STATUSES, 0));
        $this->getJson($this->url('providers'))->assertJsonPath('data', [])->assertJsonPath('meta.pagination.total', 0);
        $this->getJson($this->url('treatments'))->assertJsonPath('data', [])->assertJsonPath('meta.pagination.total', 0);
        $this->getJson($this->url('billing'))->assertJsonPath('data.by_currency', []);
    }

    private function actor(?array $grants = null, ?string $slug = null): User
    {
        $actor = User::factory()->create();
        $role = Role::firstOrCreate(['slug' => $slug ?? ($grants === null ? Role::ADMIN : 'reporter-'.fake()->uuid())], ['name' => $slug ?? 'Reporter']);
        foreach ($grants ?? [] as $grant) {
            [$resource, $action] = explode('.', $grant, 2);
            $permission = Permission::firstOrCreate(['slug' => $grant], ['name' => $grant, 'resource' => $resource, 'action' => $action]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $actor->roles()->attach($role);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function appointment(Dentist $provider, string $status = 'scheduled', string $start = '2026-10-26 09:00:00', int $minutes = 30, array $extra = []): Appointment
    {
        return Appointment::factory()->create(['dentist_id' => $provider->id, 'status' => $status, 'appointment_date_time' => $start, 'duration_minutes' => $minutes, ...$extra]);
    }

    private function invoice(string $amount, string $issuedAt = '2026-10-26 00:00:00', string $currency = 'USD', ?Appointment $appointment = null): Invoice
    {
        $invoice = Invoice::factory()->create(['amount_due' => $amount, 'currency' => $currency,
            ...($appointment ? ['appointment_id' => $appointment->id, 'patient_id' => $appointment->patient_id] : [])]);
        $invoice->update(['status' => 'issued', 'issued_at' => $issuedAt]);

        return $invoice;
    }

    private function url(string $report, array $filters = []): string
    {
        return '/api/reports/'.$report.'?'.http_build_query(array_map(fn ($value) => $value ?? '', array_replace(self::FILTERS, $filters)));
    }

    private function billingEnvelope(array $currencies): array
    {
        return ['success' => true, 'statusCode' => 200, 'message' => 'Report retrieved.', 'code' => null,
            'data' => ['by_currency' => $currencies], 'errors' => null,
            'meta' => ['filters' => [...self::FILTERS, 'dentist_id' => null], 'timezone' => 'UTC'],
            'requestId' => request()->attributes->get('request_id')];
    }
}
