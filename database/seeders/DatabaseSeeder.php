<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Treatment;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $currency = config('billing.seed_currency');
        if (! is_string($currency) || ! preg_match('/\A[A-Z]{3}\z/', $currency)) {
            throw new \RuntimeException('Set BILLING_SEED_CURRENCY to an uppercase three-letter currency before seeding.');
        }

        $this->call(TenantSeeder::class);
        $this->call(RoleSeeder::class);
        $this->call(PermissionSeeder::class);
        $this->call(AppointmentLocationSeeder::class);

        $user = User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $admin = Role::where('slug', Role::ADMIN)->firstOrFail();
        $user->tenants()->syncWithoutDetaching([app(\App\Tenancy\TenantContext::class)->id()]);
        $user->roles()->syncWithoutDetaching([$admin->id]);

        $patients = Patient::factory(10)->create();
        $dentists = Dentist::factory(4)->create();
        $treatments = Treatment::factory(6)->create();

        Appointment::factory(20)
            ->recycle($patients)
            ->recycle($dentists)
            ->recycle($treatments)
            ->create()
            ->each(function (Appointment $appointment) use ($user, $currency): void {
                $invoice = Invoice::factory()
                    ->issued()
                    ->for($appointment->patient)
                    ->for($appointment)
                    ->create(['currency' => $currency]);

                Payment::factory()->for($invoice)->create([
                    'amount' => '25.00',
                    'currency' => $invoice->currency,
                ]);

                ClinicalNote::factory()
                    ->for($user, 'author')
                    ->for($user, 'lastEditor')
                    ->for($appointment->patient)
                    ->for($appointment->dentist)
                    ->for($appointment)
                    ->create();
            });
    }
}
