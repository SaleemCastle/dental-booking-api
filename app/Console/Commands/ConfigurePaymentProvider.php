<?php

namespace App\Console\Commands;

use App\Billing\Providers\PaymentProviderRegistry;
use App\Models\PaymentLink;
use App\Models\PaymentProviderConfiguration;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigurePaymentProvider extends Command
{
    protected $signature = 'billing:configure-provider {tenant : Clinic ID} {provider=handypay}
        {--success-url= : HTTPS return URL after checkout}
        {--cancel-url= : HTTPS return URL after the buyer leaves checkout}';

    protected $description = 'Provision encrypted tenant payment credentials using a hidden prompt (never a command-line secret)';

    public function handle(TenantContext $context, PaymentProviderRegistry $providers): int
    {
        $tenant = Tenant::findOrFail($this->argument('tenant'));
        $provider = $this->argument('provider');
        $providers->resolve($provider);
        $data = [
            'provider' => $provider,
            'success_url' => $this->option('success-url'),
            'cancel_url' => $this->option('cancel-url'),
        ];
        Validator::make($data, [
            'success_url' => ['required', 'url:https', 'max:2048'],
            'cancel_url' => ['required', 'url:https', 'max:2048'],
        ])->validate();
        $key = $this->secret('Merchant API key', false);
        Validator::make(['api_key' => $key], ['api_key' => ['required', 'string', 'max:500', 'regex:/\Ahp_(test|live)_[A-Za-z0-9_-]+\z/']])->validate();
        $context->run($tenant, fn () => DB::transaction(function () use ($data, $key) {
            // Lock even when there is no configuration yet, serializing provisioning.
            Tenant::whereKey(app(TenantContext::class)->id())->lockForUpdate()->firstOrFail();
            $configuration = PaymentProviderConfiguration::query()->lockForUpdate()->first();
            if ($configuration && PaymentLink::where('payment_provider_configuration_id', $configuration->id)
                ->whereIn('status', PaymentLink::OUTSTANDING)->exists()) {
                throw ValidationException::withMessages(['provider' => ['Resolve outstanding checkouts before changing merchant configuration.']]);
            }
            ($configuration ?? new PaymentProviderConfiguration)->fill([
                ...$data, 'credentials' => ['api_key' => $key], 'enabled' => true,
            ])->save();
        }, 3));
        $this->info('Clinic payment provider configured. Credentials are encrypted and hidden from API responses.');

        return self::SUCCESS;
    }
}
