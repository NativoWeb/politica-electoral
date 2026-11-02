<?php

namespace App\Console\Commands;

use App\Services\Eventos\EventImportService;
use Illuminate\Console\Command;

// Retention of the Excel import staging of Eventos (personal data): see EventImportService::purge().
class PurgeEventImports extends Command
{
    protected $signature = 'eventos:limpiar-importaciones';

    protected $description = 'Borra las cargas (Excel o fotos de planillas) sin confirmar de más de 7 días y vacía las celdas de las confirmadas hace más de 30 días';

    public function handle(EventImportService $imports): int
    {
        $result = $imports->purge();
        $this->info("Cargas sin confirmar borradas: {$result['descartadas']}. Cargas confirmadas vaciadas: {$result['vaciadas']}.");

        return self::SUCCESS;
    }
}
