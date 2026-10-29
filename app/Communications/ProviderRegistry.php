<?php

namespace App\Communications;

final class ProviderRegistry
{
    public function resolve(string $provider): NotificationProvider
    {
        return match ($provider) {
            'smtp' => app(SmtpProvider::class),
            'twilio' => app(TwilioProvider::class),
            default => throw new DeliveryException('provider_not_configured'),
        };
    }
}
