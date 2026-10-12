<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks  (run `php artisan schedule:work`, or cron: * * * * * schedule:run)
|--------------------------------------------------------------------------
| withoutOverlapping(): a slow run never overlaps the next one.
| onOneServer(): when scaled out, only one scheduler instance dispatches the work
|                (needs a shared cache store such as Redis).
*/

// Detect tickets approaching / past their SLA and dispatch warning + escalation jobs.
Schedule::command('tickets:check-sla')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// Close tickets that stayed "Resolved" for N days.
Schedule::command('tickets:auto-close')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('queue:prune-batches --hours=48')->daily();
