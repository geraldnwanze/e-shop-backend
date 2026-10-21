<?php

namespace App\Enums;

enum PaymentProviderType: string
{
    case Paystack = 'paystack';
    case Flutterwave = 'flutterwave';
}
