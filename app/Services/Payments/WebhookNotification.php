<?php

namespace App\Services\Payments;

final readonly class WebhookNotification
{
    public function __construct(public string $eventId, public string $reference) {}
}
