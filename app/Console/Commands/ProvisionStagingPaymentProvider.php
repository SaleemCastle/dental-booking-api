<?php

namespace App\Console\Commands;

use App\Billing\Services\PaymentProviderProvisioningService;
use Illuminate\Console\Command;
use RuntimeException;

class ProvisionStagingPaymentProvider extends Command
{
    protected $signature = 'billing:provision-staging';

    protected $description = 'Provision HandyPay sandbox for staging using private environment variables';

    public function handle(
        PaymentProviderProvisioningService $provisioningService
    ): int {
        if (config('app.env') !== 'staging') {
            $this->error('Provisioning is restricted to staging.');
            return self::FAILURE;
        }

        if (! filter_var(
            env('HANDYPAY_PROVISION_ENABLED', false),
            FILTER_VALIDATE_BOOLEAN
        )) {
            $this->error('Staging provisioning is disabled.');

            return self::FAILURE;
        }

        $apiKey = env('HANDYPAY_PROVISION_API_KEY');

        if (!is_string($apiKey) || !str_starts_with($apiKey, 'hp_test_')) {
            $this->error('A HandyPay sandbox API key is required.');
            return self::FAILURE;
        }

        $tenantId = filter_var(
            env('HANDYPAY_PROVISION_TENANT_ID'),
            FILTER_VALIDATE_INT
        );

        if ($tenantId === false || $tenantId <= 0) {
            $this->error('A valid staging tenant ID is required.');
            return self::FAILURE;
        }

        $provisioningService->provision(
            tenantId: $tenantId,
            provider: 'handypay',
            apiKey: $apiKey,
            webhookSecret: (string) env('HANDYPAY_PROVISION_WEBHOOK_SECRET'),
            webhookEndpoint: (string) env('HANDYPAY_PROVISION_WEBHOOK_ENDPOINT'),
            successUrl: (string) env('HANDYPAY_PROVISION_SUCCESS_URL'),
            cancelUrl: (string) env('HANDYPAY_PROVISION_CANCEL_URL'),
            createOnly: true,
        );

        $this->info('Staging provider provisioning check completed.');

        return self::SUCCESS;
    }
}
