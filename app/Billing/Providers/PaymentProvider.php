<?php

namespace App\Billing\Providers;

interface PaymentProvider
{
    /** Creates only hosted checkouts; card data must never cross this boundary. */
    public function createCheckout(CheckoutRequest $request, #[\SensitiveParameter] array $credentials): CheckoutResult;

    public function checkoutStatus(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult;

    /** Return only after the provider confirms the checkout can no longer be paid. */
    public function cancelCheckout(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult;
}
