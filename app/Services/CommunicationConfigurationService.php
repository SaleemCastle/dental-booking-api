<?php

namespace App\Services;

use App\Communications\SafeTemplate;
use App\Models\CommunicationConfiguration;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CommunicationConfigurationService
{
    public function disable(): void
    {
        app(TenantContext::class)->requireId();
        DB::transaction(function () {
            $configuration = CommunicationConfiguration::lockForUpdate()->firstOrFail();
            $configuration->update(['enabled' => false, 'revision' => $configuration->revision + 1]);
            app(AuditLogService::class)->record('disabled', 'communication_configurations', $configuration->id, null);
        });
    }

    /** Trusted operator provisioning only; credentials never enter a public API. */
    public function configure(array $settings, bool $enabled): CommunicationConfiguration
    {
        $tenantId = app(TenantContext::class)->requireId();
        Validator::make(['settings' => $settings], [
            'settings' => 'required|array:channels,events',
            'settings.channels' => 'required|array:email,sms|min:1',
            'settings.channels.email' => 'sometimes|array:provider,sender,sender_name,host,port,username,password|min:1',
            'settings.channels.email.provider' => 'required_with:settings.channels.email|in:smtp',
            'settings.channels.email.sender' => 'required_with:settings.channels.email|email:rfc|max:254',
            'settings.channels.email.sender_name' => ['sometimes', 'string', 'max:100', 'not_regex:/[\r\n]/'],
            'settings.channels.email.host' => ['required_with:settings.channels.email', 'string', 'max:253', 'regex:/\A[A-Za-z0-9.-]+\z/'],
            'settings.channels.email.port' => 'required_with:settings.channels.email|integer|min:1|max:65535',
            'settings.channels.email.username' => 'required_with:settings.channels.email|string|max:255',
            'settings.channels.email.password' => 'required_with:settings.channels.email|string|max:1000',
            'settings.channels.sms' => 'sometimes|array:provider,sender,account_sid,auth_token|min:1',
            'settings.channels.sms.provider' => 'required_with:settings.channels.sms|in:twilio',
            'settings.channels.sms.sender' => ['required_with:settings.channels.sms', 'regex:/\A\+[1-9][0-9]{7,14}\z/'],
            'settings.channels.sms.account_sid' => ['required_with:settings.channels.sms', 'regex:/\AAC[0-9a-fA-F]{32}\z/'],
            'settings.channels.sms.auth_token' => 'required_with:settings.channels.sms|string|max:500',
            'settings.events' => 'required|array|min:1',
        ])->validate();
        if (array_diff(array_keys($settings['events']), array_keys(SafeTemplate::DEFAULTS))) {
            throw ValidationException::withMessages(['events' => ['Unsupported communication event.']]);
        }
        // Event names contain dots, so validate each event independently of Laravel's dot notation.
        foreach ($settings['events'] as $event => $rule) {
            Validator::make($rule, [
                'channels' => ['required', 'array', 'min:1', 'max:2'],
                'channels.*' => ['required', 'distinct', Rule::in(array_keys($settings['channels']))],
                'copy_user_ids' => 'sometimes|array|max:50',
                'copy_user_ids.*' => 'required|integer|distinct|min:1',
                'template' => 'sometimes|string|max:2000',
                'minutes_before' => ($event === 'appointment.reminder' ? 'required' : 'prohibited').'|array|min:1|max:10',
                'minutes_before.*' => 'required|integer|distinct|min:1|max:43200',
            ])->validate();
            if (array_diff(array_keys($rule), ['channels', 'copy_user_ids', 'template', 'minutes_before'])) {
                throw ValidationException::withMessages(['events' => ['Unsupported event setting.']]);
            }
            try {
                app(SafeTemplate::class)->validate($rule['template'] ?? SafeTemplate::DEFAULTS[$event]);
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['template' => [$error->getMessage()]]);
            }
            if (str_starts_with($event, 'appointment.') || $event === 'task.reminder') {
                if (! empty($rule['copy_user_ids'])) {
                    throw ValidationException::withMessages(['copy_user_ids' => ['Patient engagement events support patient contacts only.']]);
                }
            }
            foreach ($rule['copy_user_ids'] ?? [] as $id) {
                if (! $this->eligibleCopyUser(User::find($id))) {
                    throw ValidationException::withMessages(['copy_user_ids' => ['Copies require verified email and current-clinic billing access.']]);
                }
            }
        }

        return DB::transaction(function () use ($tenantId, $settings, $enabled) {
            Tenant::whereKey($tenantId)->where('active', true)->lockForUpdate()->firstOrFail();
            $configuration = CommunicationConfiguration::firstOrNew();
            $configuration->fill(['settings' => $settings, 'enabled' => $enabled, 'revision' => ($configuration->revision ?? 0) + 1])->save();
            app(AuditLogService::class)->record('configured', 'communication_configurations', $configuration->id, null);

            return $configuration;
        });
    }

    public function eligibleCopyUser(?User $user): bool
    {
        return $user !== null && $user->email_verified_at !== null && $user->isMemberOfCurrentTenant()
            && filter_var($user->email, FILTER_VALIDATE_EMAIL) && Gate::forUser($user)->allows('viewAny', Invoice::class);
    }
}
