<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    /**
     * Statuses under which the plan's limits apply.
     *
     * @return list<self>
     */
    public static function entitled(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }
}
