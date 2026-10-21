<?php

namespace App\Enums;

enum ApiErrorCodeEnum: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case TenantNotFound = 'TENANT_NOT_FOUND';
    case TenantSuspended = 'TENANT_SUSPENDED';
    case TenantContextMissing = 'TENANT_CONTEXT_MISSING';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case TooManyRequests = 'TOO_MANY_REQUESTS';
    case Conflict = 'CONFLICT';
    case InsufficientStock = 'INSUFFICIENT_STOCK';
    case InvalidStateTransition = 'INVALID_STATE_TRANSITION';
    case EmptyCart = 'EMPTY_CART';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case RequestInProgress = 'REQUEST_IN_PROGRESS';
    case PaymentProviderError = 'PAYMENT_PROVIDER_ERROR';
    case InvalidWebhookSignature = 'INVALID_WEBHOOK_SIGNATURE';
    case RegistrationClosed = 'REGISTRATION_CLOSED';
    case PlanLimitReached = 'PLAN_LIMIT_REACHED';
    case AccountSuspended = 'ACCOUNT_SUSPENDED';
    case StoreNotAcceptingOrders = 'STORE_NOT_ACCEPTING_ORDERS';
    case ShippingUnavailable = 'SHIPPING_UNAVAILABLE';
    case ServerError = 'SERVER_ERROR';

    public function defaultStatusCode(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden, self::RegistrationClosed, self::AccountSuspended, self::TenantSuspended, self::EmailNotVerified, self::InvalidSignature, self::StoreNotAcceptingOrders => 403,
            self::NotFound, self::TenantNotFound => 404,
            self::Conflict, self::PlanLimitReached, self::InsufficientStock, self::InvalidStateTransition, self::RequestInProgress => 409,
            self::PaymentProviderError => 502,
            self::InvalidWebhookSignature => 401,
            self::EmptyCart, self::IdempotencyKeyReused, self::ShippingUnavailable => 422,
            self::TooManyRequests => 429,
            self::TenantContextMissing, self::ServerError => 500,
        };
    }
}
