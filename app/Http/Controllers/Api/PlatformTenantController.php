<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProvisionTenantRequest;
use App\Models\Tenant;
use App\Services\TenantProvisioning;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformTenantController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Tenant::orderBy('id')->paginate(25));
    }

    public function show(Tenant $tenant)
    {
        return ApiResponse::success(['tenant' => $tenant]);
    }

    public function store(ProvisionTenantRequest $request, TenantProvisioning $workflow)
    {
        [$tenant, $created] = $workflow->provision($request->validated(), $request->user());

        return ApiResponse::success(['tenant' => $tenant, 'initial_admin_id' => $tenant->initial_admin_id], 'Clinic provisioned.', $created ? 201 : 200);
    }

    public function status(Request $request, Tenant $tenant, TenantProvisioning $workflow)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        return ApiResponse::success(['tenant' => $workflow->changeStatus($tenant, $data['status'], $request->user())], 'Clinic status updated.');
    }
}
