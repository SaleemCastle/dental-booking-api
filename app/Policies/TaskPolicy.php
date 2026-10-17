<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'view');
    }

    public function view(User $user, Task $task): bool
    {
        return $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Task $task): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->can($user, 'delete');
    }

    private function can(User $user, string $action): bool
    {
        return $user->hasRole(Role::ADMIN) || $user->canAccessResource('tasks', $action);
    }
}
