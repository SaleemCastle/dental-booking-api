<?php

namespace Tests\Fakes;

use App\Billing\Providers\CheckoutRequest;
use App\Billing\Providers\CheckoutResult;
use App\Billing\Providers\PaymentProvider;
use App\Billing\Providers\ProviderException;
use DateTimeImmutable;

class FakePaymentProvider implements PaymentProvider
{
    public array $created = [];

    public array $lookups = [];

    public array $cancellations = [];

    public array $sessions = [];

    public ?ProviderException $failure = null;

    public string $status = 'active';

    public ?string $urlOverride = null;

    public ?int $amountOverride = null;

    public ?string $currencyOverride = null;

    public function createCheckout(CheckoutRequest $request, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        $this->created[] = ['request' => $request, 'credentials' => $credentials];
        if ($this->failure) {
            throw $this->failure;
        }
        $reference = 'fake_'.$request->operationKey;
        $this->sessions[$reference] = $request;

        return $this->result($reference);
    }

    public function checkoutStatus(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        $this->lookups[] = ['reference' => $reference, 'credentials' => $credentials];
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->result($reference);
    }

    public function cancelCheckout(string $reference, #[\SensitiveParameter] array $credentials): CheckoutResult
    {
        $this->cancellations[] = ['reference' => $reference, 'credentials' => $credentials];
        if ($this->failure) {
            throw $this->failure;
        }
        $request = $this->sessions[$reference];

        return new CheckoutResult($reference, null, 'cancelled', null, $request->amountMinor, $request->currency);
    }

    private function result(string $reference): CheckoutResult
    {
        $request = $this->sessions[$reference];

        return new CheckoutResult(
            $reference, $this->urlOverride ?? 'https://checkout.example.test/'.$reference, $this->status,
            new DateTimeImmutable('+1 hour'), $this->amountOverride ?? $request->amountMinor, $this->currencyOverride ?? $request->currency,
        );
    }
}
