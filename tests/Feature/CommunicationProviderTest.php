<?php

namespace Tests\Feature;

use App\Communications\DeliveryException;
use App\Communications\DeliveryMessage;
use App\Communications\SmtpProvider;
use App\Communications\TwilioProvider;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CommunicationProviderTest extends TestCase
{
    public function test_twilio_sends_exactly_one_recipient_with_tenant_credentials_and_sender(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM'.str_repeat('b', 32), 'status' => 'queued'], 201)]);
        $settings = $this->settings();
        $reference = (new TwilioProvider)->send($this->message(), $settings);
        $this->assertSame('SM'.str_repeat('b', 32), $reference);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.$settings['account_sid'].'/Messages.json'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode($settings['account_sid'].':'.$settings['auth_token']))
            && $request->data() === ['From' => $settings['sender'], 'To' => '+15555550101', 'Body' => 'Payment update']);
        Http::assertSentCount(1);
    }

    public static function failures(): array
    {
        return [[429, 'provider_rate_limited', true, false], [400, 'provider_rejected', false, false],
            [401, 'provider_rejected', false, false], [503, 'message_acceptance_unknown', false, true],
            [201, 'message_acceptance_unknown', false, true]];
    }

    #[DataProvider('failures')]
    public function test_twilio_sanitizes_errors_and_never_blindly_retries(int $status, string $reason, bool $retryable, bool $uncertain): void
    {
        Http::fake(['*' => Http::response(['message' => 'patient@example.test secret'], $status)]);
        try {
            (new TwilioProvider)->send($this->message(), $this->settings());
            $this->fail('Expected sanitized failure.');
        } catch (DeliveryException $error) {
            $this->assertSame($reason, $error->getMessage());
            $this->assertSame($retryable, $error->retryable);
            $this->assertSame($uncertain, $error->uncertain);
        }
        Http::assertSentCount(1);
    }

    public static function statuses(): array
    {
        return [['queued', 'accepted'], ['sent', 'accepted'], ['delivered', 'delivered'], ['undelivered', 'failed'], ['failed', 'failed']];
    }

    #[DataProvider('statuses')]
    public function test_twilio_status_lookup_does_not_resend(string $remote, string $expected): void
    {
        $reference = 'SM'.str_repeat('b', 32);
        Http::fake(['*' => Http::response(['sid' => $reference, 'status' => $remote])]);
        $this->assertSame($expected, (new TwilioProvider)->status($reference, $this->settings()));
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/Messages/'.$reference.'.json'));
        Http::assertSentCount(1);
    }

    public function test_smtp_uses_plain_text_and_one_recipient_without_global_cc_or_redirects(): void
    {
        $transport = Mockery::mock(EsmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('stop')->once();
        $transport->shouldReceive('send')->once()->andReturnUsing(function (Email $email) {
            $this->assertSame('patient@example.test', $email->getTo()[0]->getAddress());
            $this->assertSame('billing@example.test', $email->getFrom()[0]->getAddress());
            $this->assertSame([], $email->getCc());
            $this->assertSame([], $email->getBcc());
            $this->assertNull($email->getHtmlBody());
            $this->assertSame('<script>{{patient_name}}</script>', $email->getTextBody());

            return new SentMessage($email, Envelope::create($email));
        });
        $provider = $this->smtp($transport);
        $reference = $provider->send(new DeliveryMessage('patient@example.test', 'Clinic payment update', '<script>{{patient_name}}</script>', 'stable-key'), ['sender' => 'billing@example.test']);
        $this->assertSame('stable-key@example.test', $reference);
    }

    public function test_smtp_connection_failure_is_retryable_and_send_failure_is_uncertain(): void
    {
        foreach ([true, false] as $connectionFailure) {
            $transport = Mockery::mock(EsmtpTransport::class);
            if ($connectionFailure) {
                $transport->shouldReceive('start')->once()->andThrow(new \RuntimeException('secret'));
                $transport->shouldNotReceive('send');
            } else {
                $transport->shouldReceive('start')->once();
                $transport->shouldReceive('send')->once()->andThrow(new \RuntimeException('secret'));
                $transport->shouldReceive('stop')->once();
            }
            try {
                $this->smtp($transport)->send(new DeliveryMessage('patient@example.test', 'Update', 'Body', 'stable-key'), ['sender' => 'billing@example.test']);
                $this->fail('Expected sanitized failure.');
            } catch (DeliveryException $error) {
                $this->assertSame($connectionFailure, $error->retryable);
                $this->assertSame(! $connectionFailure, $error->uncertain);
                $this->assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    private function smtp(EsmtpTransport $transport): SmtpProvider
    {
        return new class($transport) extends SmtpProvider
        {
            public function __construct(private readonly EsmtpTransport $fake) {}

            protected function transport(array $settings): EsmtpTransport
            {
                return $this->fake;
            }
        };
    }

    private function settings(): array
    {
        return ['account_sid' => 'AC'.str_repeat('a', 32), 'auth_token' => 'tenant-secret', 'sender' => '+15555550100'];
    }

    private function message(): DeliveryMessage
    {
        return new DeliveryMessage('+15555550101', 'Update', 'Payment update', 'stable-key');
    }
}
