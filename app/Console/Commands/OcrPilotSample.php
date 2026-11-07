<?php

namespace App\Console\Commands;

use App\Models\CampaignEvent;
use App\Support\PlanillaQr;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;

// Writes the example of eventos:ocr-piloto: two sign-in sheets of the platform filled with FAKE data in typed text
// (the real planilla PDF with the cells written) and their verdad.csv. Only to try the command: typed text is far
// easier than handwriting, so it says nothing about the accuracy on real sheets. Touches no table.
class OcrPilotSample extends Command
{
    protected $signature = 'eventos:ocr-piloto-ejemplo {carpeta : Carpeta donde se escriben las planillas y verdad.csv}';

    protected $description = 'Write two synthetic filled sign-in sheets and their truth CSV to try eventos:ocr-piloto';

    public const EVENT_ID = 'f5e5a5d0-0c0c-4c0c-8c0c-0000000000f5';

    public function handle(): int
    {
        $dir = rtrim($this->argument('carpeta'), '/\\');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            $this->error('No se pudo crear la carpeta.');

            return self::FAILURE;
        }

        $event = (new CampaignEvent)->forceFill([
            'id' => self::EVENT_ID,
            'nombre' => 'Planilla sintética de prueba (datos falsos)',
            'starts_at' => now()->startOfDay()->addHours(23),
            'sitio_encuentro' => 'Salón comunal (ejemplo)',
        ]);
        $event->setRelation('type', null)->setRelation('barrios', collect())->setRelation('municipio', null)->setRelation('provincia', null);

        $truth = [['archivo', 'pagina', 'fila', 'nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio', 'firma']];
        foreach (self::sheets() as $number => $rows) {
            $file = "planilla-{$number}.pdf";
            $filled = [];
            foreach ($rows as $row => $person) {
                $filled[$row] = $person;
                $truth[] = [$file, 1, $row, $person['nombres'], $person['apellidos'], $person['cedula'], $person['celular'], $person['fijo'],
                    $person['fecha_nacimiento'], $person['direccion'], $person['barrio'], $person['firma'] ? 'SI' : 'NO'];
            }

            Pdf::loadView('eventos.planilla', [
                'evento' => $event,
                'pages' => [['numero' => $number, 'qr' => PlanillaQr::png(PlanillaQr::payload(self::EVENT_ID, $number))]],
                'total' => 2,
                'filas' => 12,
                'cuando' => null,
                'relleno' => [$number => $filled],
            ])->setPaper('letter', 'landscape')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isPhpEnabled', false)
                ->setOption('isFontSubsettingEnabled', true)
                ->save($dir.DIRECTORY_SEPARATOR.$file);
        }

        $csv = fopen($dir.DIRECTORY_SEPARATOR.'verdad.csv', 'w');
        foreach ($truth as $line) {
            fputcsv($csv, $line, ',', '"', '');
        }
        fclose($csv);

        $this->info("Listo: planilla-1.pdf, planilla-2.pdf y verdad.csv en {$dir}. Datos falsos y texto escrito a máquina: "
            .'sirven para probar el comando, no para medir la precisión con letra a mano.');

        return self::SUCCESS;
    }

    // [sheet number => [row => person]]: fake people, some without signature.
    public static function sheets(): array
    {
        $p = fn ($n, $a, $c, $cel, $fijo, $f, $dir, $b, $firma = true) => [
            'nombres' => $n, 'apellidos' => $a, 'cedula' => $c, 'celular' => $cel, 'fijo' => $fijo, 'fecha_nacimiento' => $f,
            'direccion' => $dir, 'barrio' => $b, 'firma' => $firma,
        ];

        return [
            1 => [
                1 => $p('ANA MARÍA', 'PRUEBA GÓMEZ', '1098000001', '3000000001', '6070001', '12/03/1985', 'CALLE 1 # 2-03', 'EJEMPLO NORTE'),
                2 => $p('LUIS', 'FICTICIO RUEDA', '1098000002', '3000000002', '', '05/11/1970', 'CARRERA 4 # 5-06', 'EJEMPLO SUR'),
                3 => $p('CARMEN', 'DEMO SUÁREZ', '63000003', '3000000003', '', '22/07/1962', 'CALLE 7 # 8-09', 'EJEMPLO NORTE'),
                4 => $p('JORGE ELIÉCER', 'MUESTRA PARRA', '91000004', '3000000004', '6070004', '01/01/1958', 'VEREDA LA PRUEBA', 'RURAL'),
                5 => $p('DIANA', 'SINTÉTICA LEÓN', '1098000005', '3000000005', '', '30/09/1999', 'CALLE 10 # 11-12', 'EJEMPLO CENTRO'),
                6 => $p('PEDRO', 'INVENTADO DÍAZ', '1098000006', '3000000006', '', '14/02/1990', 'CARRERA 13 # 14-15', 'EJEMPLO CENTRO', false),
                7 => $p('ROSA', 'FALSA MORA', '37000007', '3000000007', '6070007', '08/08/1968', 'CALLE 16 # 17-18', 'EJEMPLO SUR'),
                8 => $p('ANDRÉS', 'PRUEBA VARGAS', '1098000008', '3000000008', '', '19/06/2001', 'CARRERA 19 # 20-21', 'EJEMPLO NORTE'),
            ],
            2 => [
                1 => $p('MARTHA', 'EJEMPLO ROJAS', '63000009', '3000000009', '', '03/04/1975', 'CALLE 22 # 23-24', 'EJEMPLO ORIENTE'),
                2 => $p('CARLOS', 'NO REAL PINTO', '1098000010', '3000000010', '6070010', '27/12/1980', 'CARRERA 25 # 26-27', 'EJEMPLO ORIENTE'),
                3 => $p('LUZ DARY', 'PRUEBA CRUZ', '1098000011', '3000000011', '', '15/05/1993', 'CALLE 28 # 29-30', 'EJEMPLO SUR', false),
                4 => $p('FABIO', 'DEMO NIÑO', '91000012', '3000000012', '', '09/10/1960', 'CARRERA 31 # 32-33', 'EJEMPLO CENTRO'),
                5 => $p('SANDRA', 'FICTICIA OSPINA', '1098000013', '3000000013', '6070013', '21/01/1988', 'CALLE 34 # 35-36', 'EJEMPLO NORTE'),
            ],
        ];
    }
}
