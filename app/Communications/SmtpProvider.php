<?php

namespace App\Communications;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

class SmtpProvider implements NotificationProvider
{
    public function send(DeliveryMessage $message, array $settings): string
    {
        // A fresh transport has no global CC/BCC, development redirect or another tenant's credentials.
        $transport = $this->transport($settings);
        $email = (new Email)->from(new Address($settings['sender'], $settings['sender_name'] ?? 'Clinic'))
            ->to($message->to)->subject($message->subject)->text($message->body);
        $email->getHeaders()->addIdHeader('Message-ID', $message->operationKey.'@'.substr(strrchr($settings['sender'], '@'), 1));
        try {
            $transport->start();
        } catch (Throwable) {
            throw new DeliveryException('smtp_connection_failed', retryable: true);
        }
        try {
            $sent = $transport->send($email);

            return $sent->getMessageId();
        } catch (Throwable) {
            // The server may already have accepted DATA; automatic resends could duplicate it.
            throw new DeliveryException('smtp_acceptance_unknown', uncertain: true);
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // A disconnect after acceptance does not change the send outcome.
            }
        }
    }

    protected function transport(array $settings): EsmtpTransport
    {
        $transport = new EsmtpTransport($settings['host'], (int) $settings['port'], true);
        $transport->setUsername($settings['username']);
        $transport->setPassword($settings['password']);
        $transport->getStream()->setTimeout(15);

        return $transport;
    }
}
