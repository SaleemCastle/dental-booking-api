<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create($data);
            $this->auditLogs->record('created', 'users', $user->id, $actor?->id);

            return $user;
        }, 3);
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $current->fill($data);

            if ($current->isDirty()) {
                $current->save();
                $this->auditLogs->record('updated', 'users', $current->id, $actor->id);
            }

            return $current->refresh();
        }, 3);
    }

    public function delete(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $current->delete();
            $this->auditLogs->record('deleted', 'users', $current->id, $actor->id);
        }, 3);
    }

    public function resetPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $current->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $this->auditLogs->record('password_reset', 'users', $current->id, $current->id);
            $user->setRawAttributes($current->getAttributes(), true);
        }, 3);
    }

    public function verifyEmail(User $user): bool
    {
        return DB::transaction(function () use ($user) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($current->hasVerifiedEmail()) {
                return false;
            }

            $current->markEmailAsVerified();
            $this->auditLogs->record('email_verified', 'users', $current->id, $current->id);
            $user->setRawAttributes($current->getAttributes(), true);

            return true;
        }, 3);
    }
}
