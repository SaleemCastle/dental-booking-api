<?php

namespace App\Communications;

use Illuminate\Support\Facades\Http;
use Throwable;

class TwilioProvider implements NotificationProvider, TracksDelivery
{
    public function status(string $reference, array $settings): string
    {
        if (! preg_match('/\ASM[0-9a-fA-F]{32}\z/', $reference)) {
            throw new DeliveryException('provider_response_invalid');
        }
        try {
            $response = Http::withBasicAuth($settings['account_sid'], $settings['auth_token'])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->get('https://api.twilio.com/2010-04-01/Accounts/'.$settings['account_sid'].'/Messages/'.$reference.'.json');
        } catch (Throwable) {
            throw new DeliveryException('delivery_status_unavailable');
        }
        if (! $response->successful() || $response->json('sid') !== $reference) {
            throw new DeliveryException('delivery_status_unavailable');
        }

        return match ($response->json('status')) {
            'delivered', 'read' => 'delivered',
            'failed', 'undelivered', 'canceled' => 'failed',
            default => 'accepted',
        };
    }

    public function send(DeliveryMessage $message, array $settings): string
    {
        try {
            $response = Http::asForm()->withBasicAuth($settings['account_sid'], $settings['auth_token'])
                ->connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->post('https://api.twilio.com/2010-04-01/Accounts/'.$settings['account_sid'].'/Messages.json', [
                    'From' => $settings['sender'], 'To' => $message->to, 'Body' => $message->body,
                ]);
        } catch (Throwable) {
            throw new DeliveryException('message_acceptance_unknown', uncertain: true);
        }
        if ($response->status() === 429) {
            throw new DeliveryException('provider_rate_limited', retryable: true);
        }
        if ($response->clientError()) {
            throw new DeliveryException('provider_rejected');
        }
        if (! $response->successful() || ! preg_match('/\ASM[0-9a-fA-F]{32}\z/', (string) $response->json('sid'))) {
            throw new DeliveryException('message_acceptance_unknown', uncertain: true);
        }

        return $response->json('sid');
    }
}
