<?php

namespace App\Billing\Providers;

interface WebhookPaymentProvider extends PaymentProvider
{
    public function verifyWebhook(#[\SensitiveParameter] string $body, #[\SensitiveParameter] string $signature, #[\SensitiveParameter] array $credentials): VerifiedPaymentEvent;
}
