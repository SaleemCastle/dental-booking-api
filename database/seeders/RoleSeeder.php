<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Seed stable system roles used by policies and frontend navigation.
     */
    public function run(): void
    {
        collect($this->roles())->each(function (array $role): void {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system' => true,
                ],
            );
        });
    }

    private function roles(): array
    {
        return [
            [
                'name' => 'Admin',
                'slug' => Role::ADMIN,
                'description' => 'Full administrative access across the clinic workspace.',
            ],
            [
                'name' => 'Dentist',
                'slug' => Role::DENTIST,
                'description' => 'Clinical provider access for patient care and treatment workflows.',
            ],
            [
                'name' => 'Hygienist',
                'slug' => Role::HYGIENIST,
                'description' => 'Clinical support access for hygiene and patient care workflows.',
            ],
            [
                'name' => 'Receptionist',
                'slug' => Role::RECEPTIONIST,
                'description' => 'Front desk access for scheduling and patient intake workflows.',
            ],
            [
                'name' => 'Billing',
                'slug' => Role::BILLING,
                'description' => 'Billing access for invoices, payments, and collections workflows.',
            ],
            [
                'name' => 'Auditor',
                'slug' => Role::AUDITOR,
                'description' => 'Read-oriented access for operational review and compliance workflows.',
            ],
        ];
    }
}
