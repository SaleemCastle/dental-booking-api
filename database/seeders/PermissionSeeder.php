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
                    // Sensitive domain permissions require explicit clinic role grants.
                    if (! in_array($resource, ['patient_insurance', 'insurance_catalog', 'insurance_documents', 'insurance_estimates', 'insurance_claims', 'insurance_prior_authorizations', 'insurance_remittances', 'insurance_receipts', 'insurance_obligations', 'insurance_reports', 'document_holds', 'document_quarantine', 'inventory', 'suppliers', 'purchase_orders', 'supplier_receipts', 'supplier_invoices', 'equipment', 'inventory_reports'], true)) {
                        $permissionIds[] = $permission->id;
                    }
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
            'provider_availabilities' => ['view', 'update'],
            'dentists' => $crud,
            'treatments' => $crud,
            'invoices' => ['view', 'create', 'update'],
            'payments' => ['view', 'create'],
            'payment_links' => ['view', 'create', 'cancel', 'refresh'],
            'clinical_notes' => $crud,
            'patient_documents' => ['view', 'create', 'archive', 'delete'],
            'dental_charts' => ['view', 'create'],
            'treatment_plans' => ['view', 'create', 'update'],
            'tasks' => $crud,
            'messages' => $crud,
            'messaging' => ['internal.send', 'internal.receive', 'patient.send', 'preferences.manage',
                'internal.view', 'patient.view', 'settings.manage', 'history.view', 'assign', 'escalate', 'supervise', 'archive', 'redact', 'export'],
            'referrals' => $crud,
            'users' => $crud,
            'roles' => $crud,
            'permissions' => ['view'],
            'audit_logs' => ['view'],
            'insurance_documents' => ['view', 'upload', 'link', 'tenant_view', 'tenant_upload'],
            'document_holds' => ['manage'],
            'document_quarantine' => ['manage'],
            'insurance_catalog' => ['view', 'manage', 'review', 'publish'],
            'patient_insurance' => ['view', 'manage', 'reveal'],
            'insurance_estimates' => ['view', 'create'],
            'insurance_claims' => ['view', 'prepare', 'submit', 'responses.record', 'responses.view', 'appeals.manage', 'appeals.view', 'corrections.prepare'],
            'insurance_prior_authorizations' => ['view', 'manage', 'decide', 'evidence'],
            'insurance_remittances' => ['view', 'record', 'correct'],
            'insurance_receipts' => ['view', 'record', 'verify'],
            'inventory' => ['view', 'manage_catalog', 'record_movements', 'correct_movements'],
            'inventory_reports' => ['view'],
            'suppliers' => ['view', 'manage'],
            'purchase_orders' => ['view', 'manage'],
            'supplier_receipts' => ['view', 'record'],
            'supplier_invoices' => ['view', 'record'],
            'equipment' => ['view', 'manage', 'record_history'],
            'insurance_obligations' => ['view', 'propose', 'approve'],
            'insurance_reports' => ['view'],
        ];
    }
}
