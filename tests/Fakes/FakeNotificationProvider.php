<?php

namespace Tests\Fakes;

use App\Communications\DeliveryMessage;
use App\Communications\NotificationProvider;

class FakeNotificationProvider implements NotificationProvider
{
    public array $messages = [];

    public array $errors = [];

    public function send(DeliveryMessage $message, array $settings): string
    {
        $this->messages[] = ['message' => $message, 'settings' => $settings];
        if ($error = array_shift($this->errors)) {
            throw $error;
        }

        return 'accepted-'.$message->operationKey;
    }
}
