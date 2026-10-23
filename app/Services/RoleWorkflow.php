<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoleWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, User $actor): Role
    {
        $this->authorize($actor);
        $validated = $this->validate($data);

        return DB::transaction(function () use ($validated, $actor) {
            $role = Role::create($validated);
            $this->auditLogs->record('created', 'roles', $role->id, $actor->id);

            return $role->refresh();
        }, 3);
    }

    public function update(Role $role, array $data, User $actor): Role
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($role, $data, $actor) {
            $current = Role::query()->lockForUpdate()->findOrFail($role->id);
            $current->fill($this->validate($data, $current));

            if ($current->is_system && $current->isDirty('slug')) {
                throw ValidationException::withMessages(['slug' => ['System role slugs cannot be changed.']]);
            }

            if ($current->isDirty()) {
                $current->save();
                $this->auditLogs->record('updated', 'roles', $current->id, $actor->id);
            }

            return $current->refresh();
        }, 3);
    }

    public function delete(Role $role, User $actor): void
    {
        $this->authorize($actor);

        DB::transaction(function () use ($role, $actor) {
            $current = Role::query()->lockForUpdate()->findOrFail($role->id);

            if ($current->is_system) {
                throw ValidationException::withMessages(['role_id' => ['System roles cannot be deleted.']]);
            }

            $userIds = $current->users()->orderBy('users.id')->lockForUpdate()->pluck('users.id');
            $current->delete();
            $this->auditLogs->record('deleted', 'roles', $current->id, $actor->id);

            foreach ($userIds as $userId) {
                $this->auditLogs->record('roles_changed', 'users', $userId, $actor->id);
            }
            $actor->unsetRelation('roles');
        }, 3);
    }

    public function syncUserRoles(User $user, array $roleIds, User $actor): User
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($user, $roleIds, $actor) {
            Validator::make(['role_ids' => $roleIds], [
                'role_ids' => ['present', 'array'],
                'role_ids.*' => ['integer', 'distinct', TenantRules::exists('roles')],
            ])->validate();
            Role::query()->whereIn('id', $roleIds)->orderBy('id')->lockForUpdate()->get();
            $current = User::forCurrentTenant()->lockForUpdate()->findOrFail($user->id);
            $changes = $current->roles()->sync($roleIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                $this->auditLogs->record('roles_changed', 'users', $current->id, $actor->id);
            }
            $user->unsetRelation('roles');
            $actor->unsetRelation('roles');

            return $current->load('roles.permissions');
        }, 3);
    }

    public function syncPermissions(Role $role, array $permissionIds, User $actor): Role
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($role, $permissionIds, $actor) {
            $current = Role::query()->lockForUpdate()->findOrFail($role->id);
            Validator::make(['permission_ids' => $permissionIds], [
                'permission_ids' => ['present', 'array'],
                'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
            ])->validate();
            Permission::query()->whereIn('id', $permissionIds)->orderBy('id')->lockForUpdate()->get();
            $changes = $current->permissions()->sync($permissionIds);

            if ($changes['attached'] !== [] || $changes['detached'] !== []) {
                $this->auditLogs->record('permissions_changed', 'roles', $current->id, $actor->id);
            }
            $role->unsetRelation('permissions');
            $actor->unsetRelation('roles');

            return $current->load('permissions');
        }, 3);
    }

    private function authorize(User $actor): void
    {
        if (! $actor->load('roles')->hasRole(Role::ADMIN)) {
            throw new AuthorizationException('Only administrators may change roles or permissions.');
        }
    }

    private function validate(array $data, ?Role $role = null): array
    {
        if (array_key_exists('is_system', $data)) {
            throw ValidationException::withMessages(['is_system' => ['The system role flag is server-controlled.']]);
        }

        return Validator::make($data, [
            'name' => [$role === null ? 'required' : 'sometimes', 'required', 'string', 'max:255'],
            'slug' => [$role === null ? 'required' : 'sometimes', 'required', 'string', 'max:255', 'regex:/\A[a-z][a-z0-9_-]*\z/', TenantRules::unique('roles', 'slug')->ignore($role?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();
    }
}
