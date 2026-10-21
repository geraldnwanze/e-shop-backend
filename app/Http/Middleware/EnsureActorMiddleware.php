<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Models\Customer;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum tokens may belong to a merchant User or a storefront Customer.
 * This keeps each token type on its own routes: `actor:user` / `actor:customer`.
 */
class EnsureActorMiddleware
{
    public function handle(Request $request, Closure $next, string $actor): Response
    {
        $class = $actor === 'customer' ? Customer::class : User::class;

        if (! $request->user() instanceof $class) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This token cannot access this resource.');
        }

        return $next($request);
    }
}
