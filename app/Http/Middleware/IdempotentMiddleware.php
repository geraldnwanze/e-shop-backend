<?php

namespace App\Http\Middleware;

use App\Services\Idempotency\IdempotencyService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requires an Idempotency-Key header. Successful (2xx) responses are stored and
 * replayed for retries; failed attempts release the key so the client can retry.
 * Must run after tenant resolution (keys are tenant-scoped).
 */
class IdempotentMiddleware
{
    public function __construct(private readonly IdempotencyService $idempotency) {}

    public function handle(Request $request, Closure $next, string $scope = 'default'): Response
    {
        $key = (string) $request->header('Idempotency-Key');

        if (! preg_match('/^[A-Za-z0-9_\-:.]{8,128}$/', $key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A valid Idempotency-Key header (8-128 chars) is required.']]);
        }

        $hash = hash('sha256', implode('|', [
            $request->method(),
            $request->path(),
            $request->user('sanctum')?->getAuthIdentifier() ?? 'guest',
            json_encode($request->all()),
        ]));

        $begun = $this->idempotency->begin($scope, $key, $hash);

        if ($begun['replay'] !== null) {
            return response()->json($begun['replay']['body'], $begun['replay']['status'])->header('Idempotent-Replayed', 'true');
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->idempotency->abort($begun['record']);

            throw $e;
        }

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->idempotency->complete($begun['record'], $response->getStatusCode(), $response->getData(true));
        } else {
            $this->idempotency->abort($begun['record']);
        }

        return $response;
    }
}
