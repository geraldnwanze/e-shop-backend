<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

/**
 * Thrown when tenant-owned data is written without a resolved tenant.
 * Fails closed: a record without an owner must never be persisted.
 */
class MissingTenantContextException extends BaseApiException
{
    public function __construct(string $message = 'No tenant is set in the current context.')
    {
        parent::__construct(ApiErrorCodeEnum::TenantContextMissing, $message);
    }
}
