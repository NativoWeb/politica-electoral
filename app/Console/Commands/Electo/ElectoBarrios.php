<?php

namespace App\Console\Commands\Electo;

use App\Models\AuditLog;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\BarrioNormalizer;
use App\Services\Geo\ComunaCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// With Electo's barrio catalog (code -> name, municipio) it fills the EMPTY barrio of the fichas that kept the Electo
// code (migracion_flags.electo_codigos.barrio, docs/migracion-electo.md), marks them migracion_flags.barrio_desde_electo
// {codigo, valor} and takes barrio out of por_completar; then the comuna cascade runs for the municipios with comunas.
// Never overwrites a barrio, never uses a code of another municipio, and skips the "no barrio" codes
// (config geo.electo_barrio_codigos_sin_barrio) unless the catalog names a real barrio of that municipio. --revertir
// empties the barrio only where it is still the one written here.
class ElectoBarrios extends Command
{
    protected $signature = 'data:electo-barrios
        {csv? : Catálogo de barrios de Electo (código, nombre, municipio DANE)}
        {--dry-run : Solo muestra lo que haría, no guarda nada}
        {--revertir : Deshace lo que llenó este comando}';

    protected $description = 'Fill the empty barrio of Electo fichas from Electo\'s barrio catalog, then assign comunas';

    private const PAGE = 500;

    private const HEADERS = [
        'codigo' => ['codigo', 'id', 'idbarrio', 'pkbarrio', 'cod_barrio', 'codigo_barrio'],
        'nombre' => ['nombre', 'barrio', 'descripcion', 'nombre_barrio', 'nombrebarrio'],
        'municipio' => ['municipio', 'fkmunicipio', 'municipio_codigo', 'cod_municipio', 'codigo_municipio', 'divipola'],
    ];

    private const PLACEHOLDERS = ['SIN BARRIO', 'NINGUNO', 'NINGUNA', 'NO APLICA', 'NO REGISTRA', 'NA', 'N A', 'SIN DATO',
        'SIN INFORMACION', 'NO TIENE', 'OTRO', 'OTROS', 'POR DEFINIR', 'DESCONOCIDO'];

    public function handle(ComunaCatalog $catalog, BarrioMatcher $matcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        if ($this->option('revertir')) {
            return $this->revert($dryRun);
        }

        $path = (string) $this->argument('csv');
        if ($path === '' || ! is_file($path)) {
            $this->error('Indique el CSV del catálogo de barrios de Electo.');

            return self::FAILURE;
        }
        try {
            [$codes, $skipped] = $this->readCatalog($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sinBarrio = config('geo.electo_barrio_codigos_sin_barrio', []);
        $counts = array_fill_keys(['con_codigo', 'llenados', 'ya_tenia_barrio', 'sin_catalogo', 'otro_municipio', 'codigo_sin_barrio',
            'nombre_vacio', 'tendrian_comuna'], 0);
        $municipios = [];

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            DB::table('lideres')->whereNotNull('migracion_flags->electo_codigos->barrio')
                ->select(['id', 'campaign_id', 'geographic_unit_id', 'barrio', 'migracion_flags'])
                ->chunkById(self::PAGE, function ($rows) use ($codes, $sinBarrio, $catalog, $matcher, $dryRun, &$counts, &$municipios) {
                    foreach ($rows as $row) {
                        $flags = json_decode((string) $row->migracion_flags, true) ?: [];
                        $code = trim((string) ($flags['electo_codigos']['barrio'] ?? ''));
                        if ($code === '') {
                            continue;
                        }
                        $counts['con_codigo']++;
                        $entry = $codes[$code] ?? null;
                        if (! $entry) {
                            $counts[array_key_exists($code, $codes) ? 'nombre_vacio' : 'sin_catalogo']++;

                            continue;
                        }
                        if ($entry['municipio_id'] === null || $entry['municipio_id'] !== $row->geographic_unit_id) {
                            $counts['otro_municipio']++;

                            continue;
                        }
                        $isBarrio = $catalog->hasComunas($row->geographic_unit_id)
                            && $matcher->match($row->geographic_unit_id, $row->campaign_id, $entry['nombre'])['status'] === BarrioMatcher::ASIGNADO;
                        if (in_array($code, $sinBarrio, true) && ! $isBarrio) {
                            $counts['codigo_sin_barrio']++;

                            continue;
                        }
                        if (trim((string) $row->barrio) !== '') {
                            $counts['ya_tenia_barrio']++;

                            continue;
                        }

                        $counts['llenados']++;
                        $counts['tendrian_comuna'] += $isBarrio ? 1 : 0;
                        $municipios[$row->geographic_unit_id] = true;
                        if ($dryRun) {
                            continue;
                        }
                        $flags['barrio_desde_electo'] = ['codigo' => $code, 'valor' => $entry['nombre']];
                        if (isset($flags['por_completar'])) {
                            $flags['por_completar'] = array_values(array_diff($flags['por_completar'], ['barrio']));
                            if ($flags['por_completar'] === []) {
                                unset($flags['por_completar']);
                            }
                        }
                        DB::table('lideres')->where('id', $row->id)->where(fn ($q) => $q->whereNull('barrio')->orWhere('barrio', ''))
                            ->update(['barrio' => $entry['nombre'], 'migracion_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                    }
                });

            if ($dryRun) {
                DB::rollBack();
            } else {
                AuditLog::create([
                    'user_id' => null,
                    'action' => 'data.electo_barrios',
                    'entity_type' => 'lider',
                    'metadata' => ['command' => 'data:electo-barrios', 'archivo' => basename($path), 'sha256' => hash_file('sha256', $path),
                        'resumen' => $counts],
                ]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->info('Catálogo: '.count(array_filter($codes)).' códigos con barrio'.($skipped ? ", {$skipped} sin nombre útil" : '').'.');
        $this->table(['Fichas con código', 'Llenadas', 'Ya tenían barrio', 'Código sin catálogo', 'Código de otro municipio',
            '«Sin barrio» (excluido)', 'Nombre vacío', 'Con comuna por ese barrio'], [[
                $counts['con_codigo'], $counts['llenados'], $counts['ya_tenia_barrio'], $counts['sin_catalogo'], $counts['otro_municipio'],
                $counts['codigo_sin_barrio'], $counts['nombre_vacio'], $counts['tendrian_comuna'],
            ]]);

        if ($dryRun) {
            $this->warn('DRY-RUN: no se guardó nada (la asignación de comunas no se corrió).');

            return self::SUCCESS;
        }

        return $this->cascade(array_keys($municipios), $catalog);
    }

    private function revert(bool $dryRun): int
    {
        $counts = ['revertidas' => 0, 'omitidas_cambiaron' => 0];
        $municipios = [];

        DB::transaction(function () use ($dryRun, &$counts, &$municipios) {
            DB::table('lideres')->whereNotNull('migracion_flags->barrio_desde_electo')
                ->select(['id', 'geographic_unit_id', 'barrio', 'migracion_flags'])
                ->chunkById(self::PAGE, function ($rows) use ($dryRun, &$counts, &$municipios) {
                    foreach ($rows as $row) {
                        $flags = json_decode((string) $row->migracion_flags, true) ?: [];
                        $written = $flags['barrio_desde_electo']['valor'] ?? null;
                        if ($written === null || $row->barrio !== $written) {
                            $counts['omitidas_cambiaron']++;

                            continue;
                        }
                        $counts['revertidas']++;
                        $municipios[$row->geographic_unit_id] = true;
                        if ($dryRun) {
                            continue;
                        }
                        unset($flags['barrio_desde_electo']);
                        $flags['por_completar'] = array_values(array_unique([...($flags['por_completar'] ?? []), 'barrio']));
                        DB::table('lideres')->where('id', $row->id)->where('barrio', $written)
                            ->update(['barrio' => null, 'migracion_flags' => json_encode($flags, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                    }
                });
            if (! $dryRun) {
                AuditLog::create(['user_id' => null, 'action' => 'data.electo_barrios_revertir', 'entity_type' => 'lider',
                    'metadata' => ['command' => 'data:electo-barrios --revertir', 'resumen' => $counts]]);
            }
        });

        $this->table(['Revertidas', 'Omitidas (el barrio cambió después)'], [[$counts['revertidas'], $counts['omitidas_cambiaron']]]);
        if ($dryRun) {
            $this->warn('DRY-RUN: no se guardó nada.');

            return self::SUCCESS;
        }

        return $this->cascade(array_keys($municipios), app(ComunaCatalog::class));
    }

    // geo:asignar-comunas for the fichas of each municipio touched that has comunas.
    private function cascade(array $municipioIds, ComunaCatalog $catalog): int
    {
        $result = self::SUCCESS;
        foreach ($municipioIds as $id) {
            if (! $catalog->hasComunas($id)) {
                continue;
            }
            $code = DB::table('geographic_units')->where('id', $id)->value('official_code');
            if (! $code) {
                continue;
            }
            $result = max($result, $this->call('geo:asignar-comunas', ['--municipio' => $code, '--tabla' => ['lideres'], '--detalle' => 10]));
        }

        return $result;
    }

    // [code => {nombre, municipio_id} | null (no useful name)], number of codes without a useful name.
    private function readCatalog(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \t\n\r\0\x0B\xEF\xBB\xBF\"")), str_getcsv($first, $delimiter, '"', ''));
        $index = [];
        foreach (self::HEADERS as $key => $names) {
            $found = array_values(array_intersect($names, $header));
            if ($found === []) {
                fclose($handle);
                throw new \InvalidArgumentException("Al catálogo le falta la columna {$key} (".implode(' / ', $names).').');
            }
            $index[$key] = array_search($found[0], $header, true);
        }

        $municipios = DB::table('geographic_units')->where('type', 'municipality')->whereNotNull('official_code')->pluck('id', 'official_code')->all();
        $codes = [];
        $skipped = 0;
        $line = 1;
        while (($data = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if ($data === [null]) {
                continue;
            }
            $code = trim((string) ($data[$index['codigo']] ?? ''));
            if ($code === '') {
                continue;
            }
            if (array_key_exists($code, $codes)) {
                fclose($handle);
                throw new \InvalidArgumentException("Línea {$line}: el código {$code} está repetido en el catálogo.");
            }
            $nombre = Str::limit(trim(preg_replace('/\s+/', ' ', (string) ($data[$index['nombre']] ?? ''))), 255, '');
            $norm = trim(preg_replace('/[^A-Z0-9]+/', ' ', BarrioNormalizer::plain($nombre)));
            if ($norm === '' || ctype_digit(str_replace(' ', '', $norm)) || in_array($norm, self::PLACEHOLDERS, true)) {
                $codes[$code] = null;
                $skipped++;

                continue;
            }
            $mpio = preg_replace('/\D/', '', (string) ($data[$index['municipio']] ?? ''));
            $mpio = $mpio === '' ? null : str_pad($mpio, 5, '0', STR_PAD_LEFT);
            $codes[$code] = ['nombre' => $nombre, 'municipio_id' => $mpio !== null ? ($municipios[$mpio] ?? null) : null];
        }
        fclose($handle);
        if ($codes === []) {
            throw new \InvalidArgumentException('El catálogo no tiene barrios.');
        }

        return [$codes, $skipped];
    }
}
