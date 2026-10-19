<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The database cache store never deletes expired rows (agent nonces, rate-limit and lockout counters).
Artisan::command('cache:prune-expired', function () {
    if (config('cache.default') !== 'database') {
        $this->info('Default cache store is not database; nothing to prune.');

        return;
    }

    $store = config('cache.stores.database');
    $connection = DB::connection($store['connection'] ?? null);
    $now = now()->getTimestamp();

    $deleted = $connection->table($store['table'] ?? 'cache')->where('expiration', '<', $now)->delete();
    $locks = $connection->table($store['lock_table'] ?? 'cache_locks')->where('expiration', '<', $now)->delete();

    $this->info("Pruned {$deleted} expired cache rows and {$locks} expired locks.");
})->purpose('Delete expired rows from the database cache tables');

Schedule::command('cache:prune-expired')->daily();
