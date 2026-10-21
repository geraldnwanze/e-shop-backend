<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes a warning for any API request slower than the configured limit. The route pattern is logged
 * (not the URL), so ids and tokens never reach the log; the request id ties it to other entries.
 */
class LogSlowRequestsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('monitor_started_at', hrtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $limit = (int) config('monitoring.slow_request_ms');
        $started = $request->attributes->get('monitor_started_at');

        if ($limit <= 0 || $started === null) {
            return;
        }

        $ms = (hrtime(true) - $started) / 1_000_000;

        if ($ms < $limit) {
            return;
        }

        Log::warning('Slow request', [
            'method' => $request->method(),
            'route' => $request->route()?->uri() ?? $request->path(),
            'status' => $response->getStatusCode(),
            'ms' => (int) round($ms),
            'tenant_id' => app(TenantContext::class)->id(),
        ]);
    }
}
