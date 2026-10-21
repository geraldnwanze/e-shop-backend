<?php

return [
    /*
    | Guard rails for promotional email sent from the platform's shared sender. They keep one store's
    | campaign from damaging delivery for everyone else.
    */
    'max_recipients_per_campaign' => (int) env('MARKETING_MAX_RECIPIENTS', 2000),
    'daily_recipient_limit' => (int) env('MARKETING_DAILY_LIMIT', 5000),

    /** Soonest and furthest ahead a campaign can be scheduled. */
    'min_schedule_minutes' => 5,
    'max_schedule_days' => 90,
];
