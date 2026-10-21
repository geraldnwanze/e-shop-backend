<?php

namespace App\Enums;

/**
 * How a customer paid in person. The cashier confirms receipt, so there is no payment provider involved.
 */
enum PosPaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Transfer => 'Bank transfer',
            self::Card => 'Card (POS terminal)',
        };
    }
}
