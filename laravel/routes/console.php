<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\RunD365Sync;
use App\Models\SystemConfig;
use App\Services\D365\D365SyncService;
use Carbon\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    $settings = SystemConfig::get('d365AutoSync', []);
    if (is_string($settings)) $settings = json_decode($settings, true) ?? [];
    if (! is_array($settings) || ! ($settings['enabled'] ?? false)) return;

    $now = Carbon::now('Asia/Jakarta');
    if ($now->format('H:i') < ($settings['run_time'] ?? '01:00')) return;
    if (($settings['last_run_date'] ?? null) === $now->toDateString()) return;

    $lock = Cache::lock('d365-auto-sync-dispatch-'.$now->toDateString(), 120);
    if (! $lock->get()) return;

    try {
        $sync = app(D365SyncService::class);
        if (! $sync->client()->enabled()) return;

        $hasRunningLog = DB::table('d365_sync_logs')->where('status', 'running')->exists();
        $hasQueuedJob = DB::table('jobs')->where('queue', 'default')->where('payload', 'like', '%RunD365Sync%')->exists();
        if ($hasRunningLog || $hasQueuedJob) return;

        $date = ($settings['date_scope'] ?? 'yesterday') === 'today'
            ? $now->toDateString()
            : $now->copy()->subDay()->toDateString();

        $sync->clearStop();
        $sync->ensureQueueWorker();
        RunD365Sync::dispatch('all', $date, $date);

        $settings['last_run_date'] = $now->toDateString();
        $settings['last_run_at'] = $now->toIso8601String();
        SystemConfig::put('d365AutoSync', $settings);
    } finally {
        $lock->release();
    }
})->name('d365-auto-sync-dispatch')->everyMinute()->timezone('Asia/Jakarta')->withoutOverlapping();
