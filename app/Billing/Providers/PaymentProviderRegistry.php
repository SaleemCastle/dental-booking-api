<?php

namespace App\Billing\Providers;

use Illuminate\Contracts\Container\Container;

final class PaymentProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function resolve(string $provider): PaymentProvider
    {
        $adapter = config('billing.providers.'.$provider);
        if (! is_string($adapter) || ! is_a($adapter, PaymentProvider::class, true)) {
            throw new ProviderException('not_configured');
        }

        return $this->container->make($adapter);
    }
}
