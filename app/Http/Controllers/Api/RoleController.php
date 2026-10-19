<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\RoleWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index()
    {
        return ApiResponse::success(['roles' => Role::with('permissions')->orderBy('id')->get()], 'Roles retrieved.');
    }

    public function store(Request $request, RoleWorkflow $workflow)
    {
        $role = $workflow->create($request->all(), $request->user());

        return ApiResponse::success(['role' => $role->load('permissions')], 'Role successfully created.', 201);
    }

    public function show(Role $role)
    {
        return ApiResponse::success(['role' => $role->load('permissions')], 'Role retrieved.');
    }

    public function update(Request $request, Role $role, RoleWorkflow $workflow)
    {
        $role = $workflow->update($role, $request->all(), $request->user());

        return ApiResponse::success(['role' => $role->load('permissions')], 'Role successfully updated.');
    }

    public function destroy(Request $request, Role $role, RoleWorkflow $workflow)
    {
        $workflow->delete($role, $request->user());

        return ApiResponse::success(message: 'Role successfully deleted.');
    }

    public function permissions()
    {
        return ApiResponse::success(['permissions' => Permission::orderBy('slug')->get()], 'Permissions retrieved.');
    }

    public function updatePermissions(Request $request, Role $role, RoleWorkflow $workflow)
    {
        $validated = $request->validate(['permission_ids' => ['present', 'array']]);
        $role = $workflow->syncPermissions($role, $validated['permission_ids'], $request->user());

        return ApiResponse::success(['role' => $role], 'Role permissions successfully updated.');
    }

    public function updateUserRoles(Request $request, User $user, RoleWorkflow $workflow)
    {
        $validated = $request->validate(['role_ids' => ['present', 'array']]);
        $user = $workflow->syncUserRoles($user, $validated['role_ids'], $request->user());

        return ApiResponse::success(['user' => $user], 'User roles successfully updated.');
    }
}
