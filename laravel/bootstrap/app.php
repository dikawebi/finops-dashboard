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
        $schedule->command('d365:sync-yesterday')
            ->dailyAt('01:00')
            ->timezone('Asia/Bangkok')
            ->withoutOverlapping()
            ->when(fn (): bool => (bool) config('services.d365.enabled', false) && (bool) config('services.d365.auto_sync', false));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
