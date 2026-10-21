<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AttachRequestIdMiddleware;
use App\Http\Middleware\EnsureActorMiddleware;
use App\Http\Middleware\EnsurePlatformAdminMiddleware;
use App\Http\Middleware\IdempotentMiddleware;
use App\Http\Middleware\LogSlowRequestsMiddleware;
use App\Http\Middleware\ResolveTenantMiddleware;
use App\Http\Middleware\SetTenantFromUserMiddleware;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The Next.js storefront BFF calls this API from its own server; trust it to report the visitor's IP
        // (X-Forwarded-For) so per-IP rate limits apply to visitors, not to the BFF. Set TRUSTED_PROXIES to its IP(s), or * behind a trusted edge.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : array_filter(explode(',', (string) env('TRUSTED_PROXIES'))));

        $middleware->api(prepend: [AttachRequestIdMiddleware::class], append: [LogSlowRequestsMiddleware::class]);
        $middleware->throttleApi();

        // Tenant must be in context before route-model binding resolves tenant-owned models.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: SetTenantFromUserMiddleware::class);
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: EnsurePlatformAdminMiddleware::class);
        // Storefront routes resolve the tenant from the host BEFORE auth, so customer tokens load tenant-scoped.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenantMiddleware::class);

        $middleware->alias([
            'tenant.host' => ResolveTenantMiddleware::class,
            'tenant.user' => SetTenantFromUserMiddleware::class,
            'platform.admin' => EnsurePlatformAdminMiddleware::class,
            'actor' => EnsureActorMiddleware::class,
            'idempotent' => IdempotentMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $e, Request $request) => ApiExceptionRenderer::render($e, $request));

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
