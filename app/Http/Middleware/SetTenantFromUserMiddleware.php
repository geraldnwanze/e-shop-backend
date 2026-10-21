<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Exceptions\TenantNotFoundException;
use App\Exceptions\TenantSuspendedException;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticated routes: the tenant comes from the authenticated user, never
 * from the request. Platform admins may opt into a tenant via the acting
 * header; the header is ignored for every other role.
 */
class SetTenantFromUserMiddleware
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $user = $request->user() ?? throw new BaseApiException(ApiErrorCodeEnum::Unauthenticated, 'Unauthenticated.');

        if (! $user instanceof User) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This token cannot access this resource.');
        }

        Context::add('user_id', $user->id);

        if ($user->isSuspended()) {
            throw new BaseApiException(ApiErrorCodeEnum::AccountSuspended, 'This account is suspended.');
        }

        if ($user->isPlatformAdmin()) {
            $acting = $request->header(config('tenancy.acting_tenant_header'));

            if (is_string($acting) && $acting !== '') {
                $this->context->set(
                    Tenant::query()->where('id', $acting)->first() ?? throw new TenantNotFoundException('Tenant not found.'),
                );
            }

            return $next($request);
        }

        $tenant = $user->tenant ?? throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'This account is not attached to a store.');

        if (! $tenant->isActive()) {
            throw new TenantSuspendedException('Your store is suspended. Contact support.');
        }

        $this->context->set($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
