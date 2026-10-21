<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Domains\DnsLookup;
use App\Services\Domains\NativeDnsLookup;
use App\Services\Payments\PaymentManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(): reset between requests (Octane) and between queued jobs.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantResolver::class);
        $this->app->scoped(PaymentManager::class);
        $this->app->bind(DnsLookup::class, NativeDnsLookup::class);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Platform-wide administration (route group gate `can:manage-platform`).
        Gate::define('manage-platform', fn (User $user) => $user->hasPermission(Permission::ManagePlatform));
        Gate::define('manage-billing', fn (User $user) => $user->hasPermission(Permission::ManageBilling));

        // Slow queries are logged as SQL text only (never bound values, which can hold customer data).
        DB::listen(function (QueryExecuted $query): void {
            $limit = (int) config('monitoring.slow_query_ms');

            if ($limit > 0 && $query->time >= $limit) {
                Log::warning('Slow database query', ['ms' => (int) round($query->time), 'sql' => $query->sql, 'connection' => $query->connectionName]);
            }
        });

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Reset emails: a few per address per minute, and per visitor, so the form cannot be used to flood anyone's inbox.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('email:'.mb_strtolower((string) $request->input('email'))),
            Limit::perMinute(10)->by('ip:'.$request->ip()),
        ]);

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by(
            mb_strtolower((string) $request->input('email')).'|'.$request->ip(),
        ));
    }
}
