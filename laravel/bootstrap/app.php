<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withSchedule(function (Schedule $schedule): void {
        $interval = max(1, (int) config('services.d365.sync_interval_minutes', 15));
        $schedule->command('d365:sync --queued')->cron('*/'.$interval.' * * * *')->withoutOverlapping()->when(fn (): bool => (bool) config('services.d365.auto_sync', false));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
