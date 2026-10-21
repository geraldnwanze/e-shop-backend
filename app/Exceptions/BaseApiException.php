<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;
use RuntimeException;

class BaseApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(
        public readonly ApiErrorCodeEnum $errorCode,
        string $message = '',
        public readonly ?int $statusCode = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value);
    }

    public function statusCode(): int
    {
        return $this->statusCode ?? $this->errorCode->defaultStatusCode();
    }

    public static function invalidCredentials(): static
    {
        return new static(ApiErrorCodeEnum::InvalidCredentials, 'The provided credentials are incorrect.');
    }

    public static function emailNotVerified(): static
    {
        return new static(ApiErrorCodeEnum::EmailNotVerified, 'Your email address is not verified.');
    }

    public static function storeNotAcceptingOrders(): static
    {
        return new static(ApiErrorCodeEnum::StoreNotAcceptingOrders, 'This store is not taking orders yet. Please check back soon.');
    }

    public static function shippingUnavailable(): static
    {
        return new static(ApiErrorCodeEnum::ShippingUnavailable, "Sorry, we don't deliver to that address yet.");
    }

    public static function conflict(string $message): static
    {
        return new static(ApiErrorCodeEnum::Conflict, $message);
    }
}
