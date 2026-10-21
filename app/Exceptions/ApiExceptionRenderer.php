<?php

namespace App\Exceptions;

use App\Enums\ApiErrorCodeEnum;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps every exception raised under /api to the standard ApiResponse envelope.
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof BaseApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->statusCode(), $e->errors),
            $e instanceof ValidationException => ApiResponse::error(ApiErrorCodeEnum::ValidationFailed, 'The given data was invalid.', 422, $e->errors()),
            $e instanceof AuthenticationException => ApiResponse::error(ApiErrorCodeEnum::Unauthenticated, 'Unauthenticated.'),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error(ApiErrorCodeEnum::Forbidden, 'This action is unauthorized.'),
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(ApiErrorCodeEnum::NotFound, 'Resource not found.'),
            $e instanceof ThrottleRequestsException => self::throttled($e),
            $e instanceof HttpExceptionInterface => ApiResponse::error(ApiErrorCodeEnum::ServerError, $e->getMessage() ?: 'Request failed.', $e->getStatusCode()),
            default => ApiResponse::error(
                ApiErrorCodeEnum::ServerError,
                config('app.debug') ? $e->getMessage() : 'Something went wrong.',
            ),
        };
    }

    private static function throttled(ThrottleRequestsException $e): JsonResponse
    {
        return ApiResponse::error(ApiErrorCodeEnum::TooManyRequests, 'Too many requests.')
            ->withHeaders($e->getHeaders());
    }
}
