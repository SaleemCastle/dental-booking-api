<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $permissionIds = [];

            foreach ($this->permissions() as $resource => $actions) {
                foreach ($actions as $action) {
                    $name = Str::headline(str_replace('.', ' ', $action).' '.$resource);
                    $permission = Permission::updateOrCreate(
                        ['slug' => $resource.'.'.$action],
                        [
                            'name' => $name,
                            'resource' => $resource,
                            'action' => $action,
                            'description' => $name.'.',
                        ],
                    );
                    $permissionIds[] = $permission->id;
                }
            }

            $admin = Role::where('slug', Role::ADMIN)->first();
            $admin?->permissions()->syncWithoutDetaching($permissionIds);
        }, 3);
    }

    private function permissions(): array
    {
        $crud = ['view', 'create', 'update', 'delete'];

        return [
            'dashboard' => ['view'],
            'patients' => ['view', 'create', 'update', 'archive', 'medical.update'],
            'appointments' => $crud,
            'dentists' => $crud,
            'treatments' => $crud,
            'invoices' => $crud,
            'payments' => $crud,
            'clinical_notes' => $crud,
            'dental_charts' => ['view', 'create'],
            'tasks' => $crud,
            'messages' => $crud,
            'referrals' => $crud,
            'users' => $crud,
            'roles' => $crud,
            'permissions' => ['view'],
            'audit_logs' => ['view'],
        ];
    }
}
