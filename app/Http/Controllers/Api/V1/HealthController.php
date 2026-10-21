<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Group('Platform: Health', weight: 5)]
class HealthController extends Controller
{
    /**
     * Deeper than /up: checks the database and cache, and that the scheduler and queue worker have
     * reported in recently. 503 means a core service is down; "degraded" (200) means the scheduler or
     * a worker has gone quiet, and with ?strict=1 that also returns 503.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $token = config('monitoring.health_token');

        if ($token && ! hash_equals((string) $token, (string) $request->header('X-Health-Token'))) {
            return response()->json(['status' => false, 'message' => 'Unauthorized.'], 401);
        }

        $database = $this->works(fn () => DB::select('select 1'));
        $cache = $this->works(function (): void {
            Cache::put('monitor:ping', 'pong', 10);
            abort_unless(Cache::get('monitor:ping') === 'pong', 500);
        });

        $scheduler = $this->age('monitor:scheduler:last', (int) config('monitoring.scheduler_max_age_seconds'));
        $queue = $this->age('monitor:queue:last', (int) config('monitoring.queue_max_age_seconds'));

        $down = ! $database || ! $cache;
        $degraded = ! $scheduler['ok'] || ! $queue['ok'];
        $status = $down ? 'down' : ($degraded ? 'degraded' : 'ok');
        $code = $down || ($degraded && $request->boolean('strict')) ? 503 : 200;

        return response()->json([
            'status' => $status,
            'checks' => ['database' => $database, 'cache' => $cache, 'scheduler' => $scheduler, 'queue' => $queue],
            'time' => now()->toIso8601String(),
        ], $code);
    }

    private function works(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok: bool, ageSeconds: ?int}
     */
    private function age(string $key, int $max): array
    {
        $last = Cache::get($key);
        $age = $last === null ? null : max(0, now()->timestamp - (int) $last);

        return ['ok' => $age !== null && $age <= $max, 'ageSeconds' => $age];
    }
}
