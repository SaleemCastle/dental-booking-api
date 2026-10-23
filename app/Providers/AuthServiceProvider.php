<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\ClinicalNote;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Patient;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Policies\AppointmentPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\ClinicalNotePolicy;
use App\Policies\PatientPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TreatmentPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Appointment::class => AppointmentPolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        ClinicalNote::class => ClinicalNotePolicy::class,
        Patient::class => PatientPolicy::class,
        Task::class => TaskPolicy::class,
        Treatment::class => TreatmentPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Gate::before(function (User $user, string $ability, mixed $arguments = null) {
            $resource = is_array($arguments) ? ($arguments[0] ?? null) : $arguments;

            $tenantOwned = ($resource instanceof Model || (is_string($resource) && is_subclass_of($resource, Model::class)))
                && in_array(BelongsToTenant::class, class_uses_recursive($resource), true);

            if ($tenantOwned) {
                if (! $user->isMemberOfCurrentTenant()
                    || ($resource instanceof Model && $resource->exists && (int) $resource->tenant_id !== app(TenantContext::class)->id())) {
                    return false;
                }
            }

            if ($resource instanceof ClinicalNote || $resource === ClinicalNote::class
                || $resource instanceof AuditLog || $resource === AuditLog::class) {
                return null;
            }

            return $user->canPerform($ability, $resource) ?: null;
        });

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')."/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });

        //
    }
}
