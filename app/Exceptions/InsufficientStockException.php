<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;

class InsufficientStockException extends BaseApiException
{
    public function __construct(string $productName, int $available)
    {
        parent::__construct(
            ApiErrorCodeEnum::InsufficientStock,
            "Not enough stock for \"{$productName}\".",
            errors: ['product' => $productName, 'available' => max($available, 0)],
        );
    }
}
