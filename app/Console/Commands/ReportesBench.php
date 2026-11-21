<?php

namespace App\Console\Commands;

use App\Exports\StreamedXlsx;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\User;
use App\Services\Reportes\Fuentes\PersonasFuente;
use App\Services\Reportes\ReporteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// PRUEBA DE RENDIMIENTO del módulo Reportes (docs/reportes.md), never production: fills a throwaway database
// (name ending in _load) with synthetic people (fake names, fake cédulas, synthetic municipios) and measures the
// count, the list and the Excel against the targets of the plan. --purge removes what it created.
class ReportesBench extends Command
{
    protected $signature = 'reportes:bench
        {--seed=0 : Synthetic people to create before measuring (130000 in the plan)}
        {--purge : Remove the synthetic people, municipios and user instead}
        {--runs=20 : Runs per measure (p95 of them)}
        {--excel=50000 : Rows of the measured Excel}
        {--force : Allow a database whose name does not end in _load}';

    protected $description = 'PRUEBA DE RENDIMIENTO: conteo, lista y Excel de Reportes con personas sintéticas (nunca en producción)';

    public const MARCA = 'BENCH-REPORTES';

    private const EMAIL = 'bench-reportes@example.test';

    private const BARRIOS = ['CENTRO', 'SAN FRANCISCO', 'LA CONCORDIA', 'CABECERA', 'PROVENZA', 'EL PRADO', 'GIRARDOT', 'LA AURORA'];

    private const NOMBRES = ['JUAN', 'MARIA', 'CARLOS', 'ANA', 'LUIS', 'SANDRA', 'JORGE', 'CLAUDIA', 'PEDRO', 'MARTHA', 'JOSE', 'LUZ'];

    private const APELLIDOS = ['GARCIA', 'RODRIGUEZ', 'MARTINEZ', 'GOMEZ', 'LOPEZ', 'GONZALEZ', 'HERNANDEZ', 'SANCHEZ', 'RAMIREZ', 'PEREZ'];

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (app()->environment('production') || (! str_ends_with($database, '_load') && ! $this->option('force'))) {
            $this->error("Solo para una BD de prueba de carga (*_load). BD actual: {$database}");

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $borradas = DB::table('lideres')->where('convenio', self::MARCA)->delete();
            DB::table('geographic_units')->where('canonical_name', 'like', 'Sintético %')->delete();
            DB::table('users')->where('email', self::EMAIL)->delete();
            $this->info("Borradas {$borradas} personas sintéticas.");

            return self::SUCCESS;
        }

        $municipios = $this->municipios();
        if ((int) $this->option('seed') > 0) {
            $this->sembrar($municipios, (int) $this->option('seed'));
        }
        $user = $this->usuario();
        if (! $user) {
            $this->error('No hay rol R02_ADMIN_FUNCIONAL: corra php artisan db:seed --class=RolesAndPermissionsSeeder en esta BD.');

            return self::FAILURE;
        }
        Auth::guard('web')->setUser($user);
        request()->setUserResolver(fn () => $user);
        DB::statement('ANALYZE lideres');

        $service = app(ReporteService::class);
        $runs = max(1, (int) $this->option('runs'));
        $total = DB::table('lideres')->where('convenio', self::MARCA)->count();
        $this->info("Personas sintéticas: {$total}");

        $cinco = ['municipio' => array_slice(array_keys($municipios), 0, 2), 'tipo' => ['votante', 'directorio'], 'zona' => ['urbana'],
            'edad' => ['desde' => 26, 'hasta' => 60], 'barrio' => array_slice(self::BARRIOS, 0, 4)];
        $columnas = ['nombre', 'municipio', 'barrio', 'zona', 'tipo', 'cedula', 'telefono', 'edad', 'nivel_confianza', 'referente'];
        $spec = fn (array $filtros, array $cols = ['nombre'], string $orden = 'nombre') => $service->preparar(PersonasFuente::CLAVE,
            ['v' => 1, 'filtros' => $filtros, 'columnas' => $cols, 'orden' => ['campo' => $orden, 'dir' => $orden === 'referidos' ? 'desc' : 'asc']], $user, true);

        $resultados = [];
        [$f, $s] = $spec($cinco);
        $resultados[] = $this->medir('Conteo con 5 filtros', $runs, fn () => $f->contar($s, $user), 500, 'p95');
        [$f, $s] = $spec([]);
        $resultados[] = $this->medir('Conteo sin filtros', $runs, fn () => $f->contar($s, $user), 500, 'p95');
        [$f, $s] = $spec([], $columnas);
        $resultados[] = $this->medir('Lista página 1 (10 columnas)', $runs, fn () => $f->pagina($s, $user, 1, 50), 300, 'p95');
        $resultados[] = $this->medir('Lista página 1001 (offset 50.000)', max(3, intdiv($runs, 4)), fn () => $f->pagina($s, $user, 1001, 50), 1000, 'p95');
        [$f, $s] = $spec([], ['nombre', 'referidos'], 'referidos');
        $resultados[] = $this->medir('Lista ordenada por personas referidas', max(3, intdiv($runs, 4)), fn () => $f->pagina($s, $user, 1, 50), 1000, 'p95');

        [$f, $s] = $spec([], $columnas);
        $filas = min((int) $this->option('excel'), $total);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $inicio = hrtime(true);
        $response = StreamedXlsx::download($f->filas($s, $user, $filas), $s->columnas, 'Reporte', 'bench.xlsx');
        $bytes = 0;
        ob_start(function (string $chunk) use (&$bytes) {
            $bytes += strlen($chunk);

            return '';
        }, 1 << 20);
        $response->sendContent();
        ob_end_clean();
        $ms = (hrtime(true) - $inicio) / 1e6;
        $pico = memory_get_peak_usage(true) / 1048576;
        $resultados[] = ["Excel {$filas} × ".count($columnas), round($ms), 60000, $ms < 60000 && $pico < 256 ? 'OK' : 'NO',
            sprintf('pico %.0f MB, %.1f MB de archivo', $pico, $bytes / 1048576)];

        $this->table(['Medida', 'ms', 'meta ms', 'Cumple', 'Detalle'], $resultados);

        return collect($resultados)->every(fn ($r) => $r[3] === 'OK') ? self::SUCCESS : self::FAILURE;
    }

    private function medir(string $nombre, int $runs, \Closure $fn, int $meta, string $estadistico): array
    {
        $fn();
        $tiempos = [];
        for ($i = 0; $i < $runs; $i++) {
            $inicio = hrtime(true);
            $resultado = $fn();
            $tiempos[] = (hrtime(true) - $inicio) / 1e6;
        }
        sort($tiempos);
        $p95 = $tiempos[(int) max(0, ceil(0.95 * count($tiempos)) - 1)];

        return [$nombre, round($p95), $meta, $p95 < $meta ? 'OK' : 'NO',
            sprintf('%s de %d corridas; mediana %.0f ms; %s', $estadistico, $runs, $tiempos[intdiv(count($tiempos), 2)],
                is_int($resultado) ? "total {$resultado}" : count($resultado).' filas')];
    }

    // [id => name] of the synthetic municipios (created once).
    private function municipios(): array
    {
        $existentes = DB::table('geographic_units')->where('type', 'municipality')->where('canonical_name', 'like', 'Sintético %')
            ->orderBy('canonical_name')->pluck('canonical_name', 'id')->all();
        if ($existentes) {
            return $existentes;
        }
        $prov = (string) Str::uuid();
        DB::table('geographic_units')->insert(['id' => $prov, 'type' => 'province', 'canonical_name' => 'Sintético Provincia']);
        $out = [];
        foreach (range(1, 8) as $i) {
            $id = (string) Str::uuid();
            DB::table('geographic_units')->insert(['id' => $id, 'type' => 'municipality', 'canonical_name' => "Sintético {$i}", 'parent_id' => $prov]);
            $out[$id] = "Sintético {$i}";
        }

        return $out;
    }

    // Spread like the Electo migration: the first municipio holds ~30%, then a long tail.
    private function sembrar(array $municipios, int $n): void
    {
        $ids = array_keys($municipios);
        $pesos = [30, 14, 8, 8, 5, 4, 3, 28];
        $now = now()->toDateTimeString();
        $seq = (int) DB::table('lideres')->where('convenio', self::MARCA)->count();
        $referentes = [];
        $bar = $this->output->createProgressBar($n);
        for ($hecho = 0; $hecho < $n; $hecho += 2000) {
            $filas = [];
            for ($i = $hecho; $i < min($n, $hecho + 2000); $i++) {
                $seq++;
                $r = $seq % 100;
                $m = 0;
                for ($acc = 0, $k = 0; $k < 8; $k++) {
                    $acc += $pesos[$k];
                    if ($r < $acc) {
                        $m = $k;
                        break;
                    }
                }
                $cedula = (string) (880000000 + $seq);
                $tipo = $seq % 50 === 0 ? 'directorio' : ($seq % 101 === 0 ? 'nuevo_lider' : 'votante');
                if ($tipo === 'directorio' && count($referentes) < 500) {
                    $referentes[] = $cedula;
                }
                $filas[] = [
                    'id' => (string) Str::uuid(), 'campaign_id' => Campaign::DEFAULT_ID, 'geographic_unit_id' => $ids[$m],
                    'municipio' => $municipios[$ids[$m]], 'provincia' => 'Sintético Provincia',
                    'nombre' => self::NOMBRES[$seq % 12].' '.self::NOMBRES[intdiv($seq, 12) % 12].' '.self::APELLIDOS[intdiv($seq, 7) % 10].' '.self::APELLIDOS[intdiv($seq, 97) % 10].' '.$seq,
                    'cedula' => $cedula, 'telefono' => $seq % 5 === 0 ? null : '3'.str_pad((string) $seq, 9, '0', STR_PAD_LEFT), 'tipo' => $tipo,
                    'barrio' => self::BARRIOS[$seq % 8], 'zona' => $seq % 4 === 0 ? 'rural' : 'urbana',
                    'fecha_nacimiento' => sprintf('%04d-%02d-%02d', 1945 + $seq % 60, 1 + $seq % 12, 1 + $seq % 28),
                    'referente_documento' => $seq % 3 === 0 && $referentes ? $referentes[$seq % count($referentes)] : null,
                    'gran_elector' => $seq % 37 === 0, 'militante' => $seq % 11 === 0, 'nivel_confianza' => ['sin_llamar', 'contactado', 'confirmado'][$seq % 3],
                    'is_active' => $seq % 40 !== 0, 'convenio' => self::MARCA, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('lideres')->insert($filas);
            $bar->advance(count($filas));
        }
        $bar->finish();
        $this->newLine();
    }

    private function usuario(): ?User
    {
        $role = Role::where('code', 'R02_ADMIN_FUNCIONAL')->value('id');
        if (! $role) {
            return null;
        }
        $user = User::where('email', self::EMAIL)->first() ?? User::create([
            'name' => 'Bench Reportes', 'email' => self::EMAIL, 'password' => Str::random(32), 'role_id' => $role,
        ]);
        $user->forceFill(['campaign_id' => Campaign::DEFAULT_ID, 'role_id' => $role,
            'permissions' => ['view.reportes', 'view.votantes', 'view.mapa', 'export.datos_personales', 'write.data']])->save();

        return $user->fresh();
    }
}
