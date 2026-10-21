<?php

use App\Http\Middleware\LogSlowRequestsMiddleware;
use App\Listeners\ReportFailedJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

function failedJobEvent(string $name = 'App\\Jobs\\SendCampaignEmail'): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('attempts')->andReturn(3);

    return new JobFailed('redis', $job, new RuntimeException('Mail server refused the connection'));
}

function sentAlerts(): int
{
    return count(app('mailer')->getSymfonyTransport()->messages());
}

describe('slow query and request logging', function () {
    it('logs a slow query by its SQL text only, never its bound values', function () {
        Log::spy();
        config(['monitoring.slow_query_ms' => 500]);

        DB::getEventDispatcher()->dispatch(new QueryExecuted('select * from customers where email = ?', ['ada@private.test'], 900, DB::connection()));
        DB::getEventDispatcher()->dispatch(new QueryExecuted('select 1', [], 3, DB::connection()));

        Log::shouldHaveReceived('warning')->once()->with('Slow database query', Mockery::on(fn (array $c) => $c['sql'] === 'select * from customers where email = ?' && $c['ms'] === 900 && ! str_contains(json_encode($c), 'ada@private.test')));
    });

    it('can switch slow-query logging off', function () {
        Log::spy();
        config(['monitoring.slow_query_ms' => 0]);

        DB::getEventDispatcher()->dispatch(new QueryExecuted('select 1', [], 9000, DB::connection()));

        Log::shouldNotHaveReceived('warning');
    });

    it('logs a slow request by its route pattern', function () {
        Log::spy();
        config(['monitoring.slow_request_ms' => 1]);

        $request = Request::create('/api/v1/plans', 'GET');
        $request->setRouteResolver(fn () => (new Route('GET', 'api/v1/plans', fn () => null))->bind($request));
        $middleware = new LogSlowRequestsMiddleware;

        $response = $middleware->handle($request, function () {
            usleep(5000);

            return response()->json([]);
        });
        $middleware->terminate($request, $response);

        Log::shouldHaveReceived('warning')->once()->with('Slow request', Mockery::on(fn (array $c) => $c['route'] === 'api/v1/plans' && $c['method'] === 'GET' && $c['status'] === 200 && $c['ms'] >= 1));
    });

    it('stays quiet for requests under the limit', function () {
        Log::spy();
        config(['monitoring.slow_request_ms' => 600000]);

        $this->getJson('/api/v1/plans')->assertOk();

        Log::shouldNotHaveReceived('warning');
    });
});

describe('failed job alerts', function () {
    it('logs every failure and emails the monitoring address once per job type per cooldown', function () {
        Log::spy();
        config(['monitoring.alert_email' => 'ops@platform.test']);
        $before = sentAlerts();

        $listener = app(ReportFailedJob::class);
        $listener->handle(failedJobEvent());
        $listener->handle(failedJobEvent());
        $listener->handle(failedJobEvent('App\\Jobs\\VerifyDomainJob'));

        Log::shouldHaveReceived('error')->times(3);
        expect(sentAlerts() - $before)->toBe(2);
    });

    it('only logs when no monitoring address is set', function () {
        Log::spy();
        config(['monitoring.alert_email' => null]);
        $before = sentAlerts();

        app(ReportFailedJob::class)->handle(failedJobEvent());

        Log::shouldHaveReceived('error')->once();
        expect(sentAlerts())->toBe($before);
    });
});

describe('health check', function () {
    it('is healthy once the scheduler and a queue worker have reported in', function () {
        Cache::flush();
        $this->artisan('monitor:heartbeat')->assertSuccessful();

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)->assertJsonPath('checks.scheduler.ok', true)->assertJsonPath('checks.queue.ok', true);
    });

    it('reports degraded (and 503 when strict) when the scheduler or queue has gone quiet', function () {
        Cache::flush();
        Cache::put('monitor:scheduler:last', now()->subHour()->timestamp);
        Cache::put('monitor:queue:last', now()->timestamp);

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.scheduler.ok', false)->assertJsonPath('checks.queue.ok', true);
        $this->getJson('/api/v1/health?strict=1')->assertStatus(503)->assertJsonPath('status', 'degraded');
    });

    it('reports degraded when nothing has ever reported in', function () {
        Cache::flush();

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'degraded')->assertJsonPath('checks.scheduler.ageSeconds', null);
    });

    it('requires the token when one is configured', function () {
        config(['monitoring.health_token' => 'secret-probe-token']);

        $this->getJson('/api/v1/health')->assertUnauthorized();
        $this->getJson('/api/v1/health', ['X-Health-Token' => 'wrong'])->assertUnauthorized();
        $this->getJson('/api/v1/health', ['X-Health-Token' => 'secret-probe-token'])->assertOk();
    });
});
