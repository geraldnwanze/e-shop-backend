<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('monitor:heartbeat')->everyMinute();
Schedule::command('audit:prune')->daily()->withoutOverlapping();
Schedule::command('domains:verify-pending')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('orders:expire-unpaid')->hourly()->withoutOverlapping();
Schedule::command('carts:send-abandoned-reminders')->hourly()->withoutOverlapping();
Schedule::command('campaigns:send-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:run-recurring')->everyMinute()->withoutOverlapping();
Schedule::command('stock:notify-back-in-stock')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('reviews:send-digests')->hourly()->withoutOverlapping();
Schedule::command('reviews:prune-photos')->hourly()->withoutOverlapping();
Schedule::command('reviews:send-requests')->hourly()->withoutOverlapping();
Schedule::command('discounts:prune-recovery-codes')->daily()->withoutOverlapping();
Schedule::command('billing:process')->hourly()->withoutOverlapping();
