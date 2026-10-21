<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | Entries older than this many days are removed by the daily audit:prune
    | job. Set to 0 to keep everything forever.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 730),
];
