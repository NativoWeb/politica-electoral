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

// Retention of the Excel import staging of Eventos (docs/eventos-api.md section 11). Needs the system cron
// "* * * * * php artisan schedule:run" on the server.
Schedule::command('eventos:limpiar-importaciones')->dailyAt('03:30');

// Daily e-mail with each manager's overdue, due-soon and reminded compromisos (docs/eventos-api.md section 14).
Schedule::command('compromisos:resumen-diario')->dailyAt('07:00')->timezone('America/Bogota');

// Comunas of the records that arrived by bulk loads that skip the model hooks (docs/geografia.md); never overwrites a
// manual comuna.
Schedule::command('geo:asignar-comunas --municipio=68001')->dailyAt('02:30')->timezone('America/Bogota')->withoutOverlapping();
