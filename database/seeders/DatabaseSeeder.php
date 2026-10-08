<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\ClinicalNote;
use App\Models\Dentist;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
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
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $patients = Patient::factory(10)->create();
        $dentists = Dentist::factory(4)->create();
        $treatments = Treatment::factory(6)->create();

        Appointment::factory(20)
            ->recycle($patients)
            ->recycle($dentists)
            ->recycle($treatments)
            ->create()
            ->each(function (Appointment $appointment): void {
                $invoice = Invoice::factory()
                    ->for($appointment->patient)
                    ->for($appointment)
                    ->create();

                Payment::factory()->for($invoice)->create([
                    'amount' => $invoice->amount_paid,
                    'status' => $invoice->status === 'refunded' ? 'refunded' : 'paid',
                ]);

                ClinicalNote::factory()
                    ->for($appointment->patient)
                    ->for($appointment->dentist)
                    ->for($appointment)
                    ->create();
            });
    }
}
