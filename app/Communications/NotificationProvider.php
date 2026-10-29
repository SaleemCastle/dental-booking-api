<?php

namespace App\Communications;

interface NotificationProvider
{
    /** Return a provider acceptance reference; throw only a sanitized DeliveryException. */
    public function send(DeliveryMessage $message, array $settings): string;
}
