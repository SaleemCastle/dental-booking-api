<?php

namespace App\Services;

use App\Models\DentalChartRevision;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserWorkflow
{
    public function __construct(private readonly AuditLogService $auditLogs) {}

    public function create(array $data, ?User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create($data);
            $user->tenants()->attach(app(TenantContext::class)->id());
            $this->auditLogs->record('created', 'users', $user->id, $actor?->id);

            return $user;
        }, 3);
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $current = User::forCurrentTenant()->lockForUpdate()->findOrFail($user->id);
            if ($current->id !== $actor->id && $current->tenants()->where('tenants.id', '!=', app(TenantContext::class)->id())->exists()) {
                throw new AuthorizationException('Shared user identities may only be edited by their owner.');
            }
            $current->fill($data);

            if ($current->isDirty()) {
                $current->save();
                $this->recordIdentityEvent('updated', $current, $actor->id);
            }

            return $current->refresh();
        }, 3);
    }

    public function delete(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            $current = User::forCurrentTenant()->lockForUpdate()->findOrFail($user->id);
            if ($current->assignedTasks()->exists()) {
                throw ValidationException::withMessages([
                    'user' => ['Reassign this clinic member\'s tasks before removing their membership.'],
                ]);
            }
            $current->roles()->detach();
            $current->tenants()->detach(app(TenantContext::class)->id());
            $hasOtherClinics = $current->tenants()->exists();
            // Preserve the global author identity even after its final membership
            // is removed; immutable clinical history must keep its attribution.
            $hasChartHistory = DentalChartRevision::withoutGlobalScope('tenant')->where('author_user_id', $current->id)->exists();
            if (! $hasOtherClinics && ! $hasChartHistory) {
                $current->delete();
            }
            $this->auditLogs->record($hasOtherClinics || $hasChartHistory ? 'membership_removed' : 'deleted', 'users', $current->id, $actor->id);
        }, 3);
    }

    public function resetPassword(User $user, string $password): void
    {
        DB::transaction(function () use ($user, $password) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            $current->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $this->recordIdentityEvent('password_reset', $current, $current->id);
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
            $this->recordIdentityEvent('email_verified', $current, $current->id);
            $user->setRawAttributes($current->getAttributes(), true);

            return true;
        }, 3);
    }

    private function recordIdentityEvent(string $action, User $user, int $actorId): void
    {
        foreach ($user->tenants()->get() as $tenant) {
            app(TenantContext::class)->run($tenant, fn () => $this->auditLogs->record($action, 'users', $user->id, $actorId));
        }
    }
}
