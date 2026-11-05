<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PoliticalOrganization;
use App\Services\CachedQueries;
use App\Support\PartyLogos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Party names in capitals: political_organizations.canonical_name plus the free-text party of lideres and
// lider_info_politica, so filters that compare by text keep matching. Two parties that would end with the same
// name are never merged: the command lists them and stops. Idempotent; --dry-run only reads.
class PartidosMayusculas extends Command
{
    protected $signature = 'data:partidos-mayusculas {--dry-run : Solo muestra el plan, no guarda nada}';

    protected $description = 'Pasa a MAYÚSCULAS los nombres de partido (organizaciones, lideres.partido, lider_info_politica.partido)';

    private const TEXT_COLUMNS = [['lideres', 'partido'], ['lider_info_politica', 'partido']];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $orgs = DB::table('political_organizations')->get(['id', 'canonical_name']);
            $collisions = $orgs->groupBy(fn ($o) => PoliticalOrganization::upperName($o->canonical_name))->filter(fn ($g) => $g->count() > 1);
            if ($collisions->isNotEmpty()) {
                DB::rollBack();
                $this->error('Hay partidos que quedarían con el mismo nombre. No se cambió nada; decide cuál conservar:');
                foreach ($collisions as $upper => $group) {
                    $this->line("  {$upper}:");
                    foreach ($group as $o) {
                        $this->line("    «{$o->canonical_name}» ({$o->id})");
                    }
                }

                return self::FAILURE;
            }

            $orgChanges = $orgs->filter(fn ($o) => $o->canonical_name !== PoliticalOrganization::upperName($o->canonical_name));
            $textChanges = [];
            foreach (self::TEXT_COLUMNS as [$table, $column]) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $textChanges["{$table}.{$column}"] = DB::table($table)->whereNotNull($column)
                    ->select($column, DB::raw('COUNT(*) as total'))->groupBy($column)->get()
                    ->filter(fn ($r) => $r->{$column} !== PoliticalOrganization::upperName($r->{$column}))
                    ->map(fn ($r) => ['table' => $table, 'column' => $column, 'from' => $r->{$column}, 'total' => (int) $r->total])
                    ->values();
            }

            $counts = ['organizaciones' => $orgChanges->count()];
            foreach ($textChanges as $key => $values) {
                $counts[$key] = $values->sum('total');
            }

            $this->info('Organizaciones a renombrar: '.$counts['organizaciones'].' de '.$orgs->count());
            foreach ($orgChanges as $o) {
                $this->line("  «{$o->canonical_name}» → «".PoliticalOrganization::upperName($o->canonical_name).'»');
            }
            foreach ($textChanges as $key => $values) {
                $this->info("{$key}: {$counts[$key]} filas en {$values->count()} valores distintos");
            }

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            if (array_sum($counts) === 0) {
                DB::rollBack();
                $this->info('Nada que cambiar.');

                return self::SUCCESS;
            }

            foreach ($orgChanges as $o) {
                DB::table('political_organizations')->where('id', $o->id)
                    ->update(['canonical_name' => PoliticalOrganization::upperName($o->canonical_name), 'updated_at' => now()]);
            }
            foreach ($textChanges as $values) {
                foreach ($values as $v) {
                    DB::table($v['table'])->where($v['column'], $v['from'])->update([$v['column'] => PoliticalOrganization::upperName($v['from'])]);
                }
            }

            AuditLog::create([
                'user_id' => null,
                'action' => 'DATA_PARTIDOS_MAYUSCULAS',
                'entity_type' => 'partido',
                'entity_id' => null,
                'metadata' => ['command' => 'data:partidos-mayusculas'] + $counts,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        Cache::forget('all_partidos');
        PartyLogos::forget();
        CachedQueries::flush();
        $this->info('Nombres de partido en MAYÚSCULAS.');

        return self::SUCCESS;
    }
}
