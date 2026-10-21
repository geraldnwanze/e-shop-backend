<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

class TenantSuspendedException extends BaseApiException
{
    public function __construct(string $message = 'This store is currently suspended.')
    {
        parent::__construct(ApiErrorCodeEnum::TenantSuspended, $message);
    }
}
