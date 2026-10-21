<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdminMiddleware
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->user() instanceof User && $request->user()->isPlatformAdmin())) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This action is unauthorized.');
        }

        if ($request->user()->isSuspended()) {
            throw new BaseApiException(ApiErrorCodeEnum::AccountSuspended, 'This account is suspended.');
        }

        // Explicit, route-group-wide switch: admin endpoints legitimately span tenants.
        $this->context->clear();
        $this->context->enableBypass();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
