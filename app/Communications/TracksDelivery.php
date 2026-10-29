<?php

namespace App\Communications;

interface TracksDelivery
{
    /** Return accepted, delivered or failed, without sending another message. */
    public function status(string $reference, array $settings): string;
}
