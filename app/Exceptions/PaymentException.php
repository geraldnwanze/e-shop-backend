<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

class PaymentException extends BaseApiException
{
    public function __construct(string $message = 'The payment provider could not process this request.')
    {
        parent::__construct(ApiErrorCodeEnum::PaymentProviderError, $message);
    }
}
