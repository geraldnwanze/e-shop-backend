<?php

namespace App\Http\Middleware;

use App\Exceptions\TenantNotFoundException;
use App\Exceptions\TenantSuspendedException;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public storefront routes: hostname -> tenant -> TenantContext.
 * The only place hostnames are interpreted for the request lifecycle.
 */
class ResolveTenantMiddleware
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();

        $host = (string) $request->header(config('tenancy.storefront_host_header'), $request->getHost());
        $tenant = $this->resolver->resolve($host) ?? throw new TenantNotFoundException;

        if (! $tenant->isActive()) {
            throw new TenantSuspendedException;
        }

        $this->context->set($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->clear();
    }
}
