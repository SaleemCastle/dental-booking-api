<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\CommunicationConfigurationService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use JsonException;

class ConfigureCommunications extends Command
{
    protected $signature = 'communications:configure {tenant : Clinic ID} {--disable : Disable delivery without changing settings}';

    protected $description = 'Provision tenant senders, encrypted credentials, event templates and authorized copy rules';

    public function handle(TenantContext $context, CommunicationConfigurationService $service): int
    {
        $tenant = Tenant::where('active', true)->findOrFail($this->argument('tenant'));

        return $context->run($tenant, function () use ($service) {
            if ($this->option('disable')) {
                $service->disable();
            } else {
                try {
                    $settings = json_decode($this->secret('Configuration JSON (hidden, including provider credentials)', false), true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw ValidationException::withMessages(['settings' => ['Provide a valid JSON object.']]);
                }
                if (! is_array($settings)) {
                    throw ValidationException::withMessages(['settings' => ['Provide a JSON object.']]);
                }
                $service->configure($settings, true);
            }
            $this->info('Clinic communication configuration saved.');

            return self::SUCCESS;
        });
    }
}
