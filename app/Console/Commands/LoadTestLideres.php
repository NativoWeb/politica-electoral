<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Load test only, never production: fills a throwaway database with synthetic people (fake names and cédulas) spread
// like the Electo migration (Bucaramanga ~39k), and removes them with --purge.
class LoadTestLideres extends Command
{
    protected $signature = 'loadtest:lideres
        {--count=130000 : Synthetic people to create}
        {--campaign= : Campaign id (default: the default campaign)}
        {--purge : Remove the synthetic people instead}
        {--force : Allow a database whose name does not end in _load}';

    protected $description = 'PRUEBA DE CARGA: crea o borra personas sintéticas en una BD de pruebas (nunca en producción)';

    public const MARKER = 'LOADTEST-B1';

    // Electo voters by municipio (diagnóstico §4); the rest is spread over the other municipios.
    private const DISTRIBUTION = [
        'Bucaramanga' => 39074, 'Floridablanca' => 17187, 'Girón' => 9244, 'Piedecuesta' => 9235,
        'Málaga' => 4779, 'Barrancabermeja' => 3907, 'San Gil' => 3170, 'Socorro' => 3065,
    ];

    private const NOMBRES = ['JUAN', 'MARIA', 'CARLOS', 'ANA', 'LUIS', 'SANDRA', 'JORGE', 'CLAUDIA', 'PEDRO', 'MARTHA',
        'JOSE', 'LUZ', 'ANDRES', 'DIANA', 'FABIO', 'GLORIA', 'OSCAR', 'PAOLA', 'RAFAEL', 'YOLANDA', 'HERNAN', 'NUBIA',
        'EDGAR', 'LILIANA', 'ALVARO', 'ROCIO', 'JAIME', 'ADRIANA', 'MAURICIO', 'CAROLINA'];

    private const APELLIDOS = ['GARCIA', 'RODRIGUEZ', 'MARTINEZ', 'GOMEZ', 'LOPEZ', 'GONZALEZ', 'HERNANDEZ', 'SANCHEZ',
        'RAMIREZ', 'PEREZ', 'DIAZ', 'MORENO', 'ROJAS', 'VARGAS', 'SUAREZ', 'ORTIZ', 'DUARTE', 'PINZON', 'SERRANO',
        'CACERES', 'MANTILLA', 'ARDILA', 'BLANCO', 'PRADA', 'URIBE', 'RUEDA', 'ACEVEDO', 'BELTRAN', 'CALDERON', 'NIÑO'];

    private const BARRIOS = ['CENTRO', 'SAN FRANCISCO', 'LA CONCORDIA', 'CABECERA', 'PROVENZA', 'EL PRADO', 'GIRARDOT',
        'LA AURORA', 'MUTIS', 'REAL DE MINAS', 'LOS PINOS', 'CIUDADELA', 'LAGOS', 'CAÑAVERAL', 'ALARCON', 'SOTOMAYOR'];

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (app()->environment('production') || (! str_ends_with($database, '_load') && ! $this->option('force'))) {
            $this->error("Solo para una BD de prueba de carga (*_load). BD actual: {$database}");

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            $deleted = DB::table('lideres')->where('convenio', self::MARKER)->delete();
            $this->info("Borradas {$deleted} personas sintéticas (y sus contactos en cascada).");

            return self::SUCCESS;
        }

        $campaignId = $this->option('campaign') ?: Campaign::DEFAULT_ID;
        $municipios = DB::table('geographic_units as g')
            ->leftJoin('geographic_units as p', 'g.parent_id', '=', 'p.id')
            ->where('g.type', 'municipality')
            ->get(['g.id', 'g.canonical_name', 'p.canonical_name as provincia'])
            ->keyBy('canonical_name');
        $plan = $this->plan($municipios->keys()->all(), (int) $this->option('count'));

        $now = now()->toDateTimeString();
        $seq = 0;
        $referentes = [];
        $bar = $this->output->createProgressBar(array_sum($plan));
        foreach ($plan as $name => $n) {
            $mun = $municipios[$name];
            for ($done = 0; $done < $n; $done += 2000) {
                $lideres = [];
                $contactos = [];
                for ($i = $done; $i < min($n, $done + 2000); $i++) {
                    $seq++;
                    $id = (string) Str::uuid();
                    $cedula = (string) (990000000 + $seq);
                    $tipo = match (true) {
                        $seq % 50 === 0 => 'directorio',
                        $seq % 101 === 0 => 'nuevo_lider',
                        default => 'votante',
                    };
                    if ($tipo === 'directorio' && count($referentes) < 500) {
                        $referentes[] = $cedula;
                    }
                    $telefono = $seq % 5 === 0 ? null : '3'.str_pad((string) (100000000 + $seq), 9, '0', STR_PAD_LEFT);
                    $lideres[] = [
                        'id' => $id, 'campaign_id' => $campaignId, 'geographic_unit_id' => $mun->id,
                        'municipio' => $mun->canonical_name, 'provincia' => $mun->provincia,
                        'nombre' => self::NOMBRES[$seq % 30].' '.self::NOMBRES[intdiv($seq, 30) % 30].' '
                            .self::APELLIDOS[intdiv($seq, 7) % 30].' '.self::APELLIDOS[intdiv($seq, 211) % 30],
                        'cedula' => $cedula, 'telefono' => $telefono, 'tipo' => $tipo,
                        'cargo' => $tipo === 'directorio' ? ($seq % 3 === 0 ? 'Directorio Municipal' : 'Líder') : null,
                        'barrio' => self::BARRIOS[$seq % 16], 'zona' => $seq % 4 === 0 ? 'rural' : 'urbana',
                        'partido' => $tipo === 'votante' ? null : 'Centro Democrático',
                        'fecha_nacimiento' => sprintf('%04d-%02d-%02d', 1950 + $seq % 55, 1 + $seq % 12, 1 + $seq % 28),
                        // One referente with ~2.000 referidos, as Electo has; the rest spread over 500 others.
                        'referente_documento' => $seq % 3 === 0 && $referentes
                            ? ($seq % 21 === 0 ? $referentes[0] : $referentes[$seq % count($referentes)]) : null,
                        'is_active' => $seq % 40 !== 0, 'destacado' => $seq % 997 === 0,
                        'convenio' => self::MARKER, 'created_at' => $now, 'updated_at' => $now,
                    ];
                    $contactos[] = ['id' => (string) Str::uuid(), 'lider_id' => $id, 'tipo' => 'celular',
                        'valor' => '31'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT), 'principal' => true,
                        'estado' => 'activo', 'created_at' => $now, 'updated_at' => $now];
                    if ($seq % 7 === 0) {
                        $contactos[] = ['id' => (string) Str::uuid(), 'lider_id' => $id, 'tipo' => 'email',
                            'valor' => "persona{$seq}@example.test", 'principal' => false,
                            'estado' => 'activo', 'created_at' => $now, 'updated_at' => $now];
                    }
                }
                DB::table('lideres')->insert($lideres);
                foreach (array_chunk($contactos, 2000) as $chunk) {
                    DB::table('lider_contactos')->insert($chunk);
                }
                $bar->advance(count($lideres));
            }
        }
        $bar->finish();
        $this->newLine();
        DB::statement('ANALYZE lideres');
        DB::statement('ANALYZE lider_contactos');
        $this->info("Creadas {$seq} personas sintéticas.");

        return self::SUCCESS;
    }

    // Municipio => how many: the known counts (scaled if --count is smaller), the rest evenly over the others.
    private function plan(array $names, int $count): array
    {
        $known = array_intersect_key(self::DISTRIBUTION, array_flip($names));
        $scale = min(1, $count / max(1, array_sum(self::DISTRIBUTION) * 1.1));
        $plan = array_map(fn ($n) => (int) floor($n * $scale), $known);
        $others = array_values(array_diff($names, array_keys($known)));
        $rest = max(0, $count - array_sum($plan));
        foreach ($others as $i => $name) {
            $plan[$name] = intdiv($rest, count($others)) + ($i < $rest % count($others) ? 1 : 0);
        }

        return array_filter($plan);
    }
}
