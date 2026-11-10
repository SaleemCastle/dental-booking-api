<?php

namespace App\Billing\Services;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentProviderProvisioningService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PaymentProviderRegistry $providers,
    ) {}

    public function provision(
        int $tenantId,
        string $provider,
        string $apiKey,
        string $webhookSecret,
        string $webhookEndpoint,
        string $successUrl,
        string $cancelUrl,
        bool $createOnly = false,
    ): void {
        Validator::make([
            'provider' => $provider,
            'api_key' => $apiKey,
            'webhook_secret' => $webhookSecret,
            'webhook_endpoint' => $webhookEndpoint,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], [
            'provider' => ['required', 'string'],
            'api_key' => [
                'required',
                'string',
                'max:500',
                'regex:/\Ahp_(test|live)_[A-Za-z0-9_-]+\z/',
            ],
            'webhook_secret' => ['required', 'string', 'min:16', 'max:500'],
            'webhook_endpoint' => ['required', 'uuid'],
            'success_url' => ['required', 'url:https', 'max:2048'],
            'cancel_url' => ['required', 'url:https', 'max:2048'],
        ])->validate();

        $this->providers->resolve($provider);

        $tenant = Tenant::findOrFail($tenantId);

        $this->tenantContext->run($tenant, function () use (
            $provider,
            $apiKey,
            $webhookSecret,
            $webhookEndpoint,
            $successUrl,
            $cancelUrl,
            $createOnly,
        ): void {
            DB::transaction(function () use (
                $provider,
                $apiKey,
                $webhookSecret,
                $webhookEndpoint,
                $successUrl,
                $cancelUrl,
                $createOnly,
            ): void {
                Tenant::whereKey($this->tenantContext->id())
                    ->lockForUpdate()
                    ->firstOrFail();

                $existingConfiguration = PaymentProviderConfiguration::query()
                    ->where('enabled', true)
                    ->lockForUpdate()
                    ->first();

                if ($createOnly && $existingConfiguration !== null) {
                    $configurationMatches = (
                        $existingConfiguration->provider === $provider
                        && $existingConfiguration->webhook_endpoint === $webhookEndpoint
                        && $existingConfiguration->success_url === $successUrl
                        && $existingConfiguration->cancel_url === $cancelUrl
                    );

                    if (!$configurationMatches) {
                        throw ValidationException::withMessages([
                            'provider' => [
                                'An existing payment provider configuration does not match '
                                    . 'the requested staging configuration. '
                                    . 'Provisioning was stopped without making changes.',
                            ],
                        ]);
                    }

                    // Existing configuration matches the requested non-secret settings.
                    // Do not rotate credentials during repeated staging deployments.
                    return;
                }

                $endpointAlreadyExists = PaymentProviderConfiguration::query()
                    ->where('webhook_endpoint', $webhookEndpoint)
                    ->exists();

                if ($endpointAlreadyExists) {
                    throw ValidationException::withMessages([
                        'webhook_endpoint' => [
                            'Webhook endpoint is already assigned.',
                        ],
                    ]);
                }

                if ($existingConfiguration !== null) {
                    $hasOutstandingCheckout = PaymentLink::query()
                        ->where(
                            'payment_provider_configuration_id',
                            $existingConfiguration->id
                        )
                        ->whereIn('status', PaymentLink::OUTSTANDING)
                        ->exists();

                    if ($hasOutstandingCheckout) {
                        throw ValidationException::withMessages([
                            'provider' => [
                                'Resolve outstanding checkouts before changing merchant configuration.',
                            ],
                        ]);
                    }
                }

                $sandboxVerified = PaymentProviderConfiguration::query()
                    ->where('provider', $provider)
                    ->whereNotNull('sandbox_verified_at')
                    ->exists();

                if (str_starts_with($apiKey, 'hp_live_') && !$sandboxVerified) {
                    throw ValidationException::withMessages([
                        'provider' => [
                            'Complete an authoritative sandbox settlement before provisioning live credentials.',
                        ],
                    ]);
                }

                $existingConfiguration?->update(['enabled' => false]);

                $configuration = new PaymentProviderConfiguration([
                    'provider' => $provider,
                    'credentials' => [
                        'api_key' => $apiKey,
                        'webhook_secret' => $webhookSecret,
                    ],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'enabled' => true,
                ]);

                $configuration->webhook_endpoint = $webhookEndpoint;
                $configuration->save();
            }, 3);
        });
    }
}
