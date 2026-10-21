<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Slow query and slow request logging
    |--------------------------------------------------------------------------
    |
    | Anything slower than these many milliseconds is written to the log as a
    | warning (SQL text only, never bound values). Set to 0 to switch one off.
    |
    */

    'slow_query_ms' => (int) env('MONITOR_SLOW_QUERY_MS', 500),

    'slow_request_ms' => (int) env('MONITOR_SLOW_REQUEST_MS', 2000),

    /*
    |--------------------------------------------------------------------------
    | Failed job alerts
    |--------------------------------------------------------------------------
    |
    | Every failed queued job is logged as an error. When an address is set it
    | is also emailed, at most once per job type within the cooldown.
    |
    */

    'alert_email' => env('MONITOR_ALERT_EMAIL'),

    'alert_cooldown_minutes' => (int) env('MONITOR_ALERT_COOLDOWN_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Deep health check (GET /api/v1/health)
    |--------------------------------------------------------------------------
    |
    | When a token is set, callers must send it in the X-Health-Token header.
    | The scheduler and queue worker report in every minute; they count as
    | stalled once they have been silent for longer than these limits.
    |
    */

    'health_token' => env('MONITOR_HEALTH_TOKEN'),

    'scheduler_max_age_seconds' => 180,

    'queue_max_age_seconds' => 600,
];
