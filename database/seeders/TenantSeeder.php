<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(['slug' => Tenant::DEFAULT_SLUG], ['name' => 'Default Clinic', 'active' => true]);
    }
}
