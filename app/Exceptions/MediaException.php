<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

class MediaException extends BaseApiException
{
    public function __construct(string $message = 'Media storage failed.')
    {
        parent::__construct(ApiErrorCodeEnum::ServerError, $message, 502);
    }
}
