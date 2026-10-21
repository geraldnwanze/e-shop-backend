<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

class TenantNotFoundException extends BaseApiException
{
    public function __construct(string $message = 'Store not found.')
    {
        parent::__construct(ApiErrorCodeEnum::TenantNotFound, $message);
    }
}
