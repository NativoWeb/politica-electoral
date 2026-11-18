<?php

namespace App\Services\Electo;

use Illuminate\Support\Facades\DB;

// Runs the parts of the Electo import in dependency order. Every part reads its CSV in batches; a real run commits
// each batch (resumable: what is already in electo_import_rows is skipped), a dry run wraps EVERYTHING in one
// transaction that is rolled back, so it produces the same report without leaving anything behind.
class ElectoImporter
{
    public const PARTES = ['personas', 'contactos', 'mapa', 'eventos', 'funcionarios'];

    private const CLASES = [
        'personas' => PersonasImporter::class,
        'contactos' => ContactosImporter::class,
        'mapa' => MapaImporter::class,
        'eventos' => EventosImporter::class,
        'funcionarios' => FuncionariosImporter::class,
    ];

    private const ANALYZE = [
        'personas' => ['lideres', 'electo_import_rows'],
        'contactos' => ['lider_contactos', 'lideres', 'electo_import_rows'],
        'mapa' => ['lider_info_politica', 'lider_municipios_influencia', 'electo_import_rows'],
        'eventos' => ['events', 'event_attendees', 'electo_import_rows'],
        'funcionarios' => ['funcionario_entidades', 'funcionario_contratos', 'electo_import_rows'],
    ];

    public function __construct(private readonly ImportContext $ctx) {}

    // Problems that stop the import before reading any file.
    public function preflight(array $partes): array
    {
        $errors = [];
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return ['electo:importar necesita PostgreSQL.'];
        }
        if (! DB::table('campaigns')->where('id', $this->ctx->campaignId)->exists()) {
            $errors[] = 'No existe el grupo político '.$this->ctx->campaignId.'.';
        }
        foreach (['lideres.origen', 'lideres.migracion_flags', 'lider_contactos.whatsapp', 'events.origen', 'event_attendees.origen'] as $column) {
            [$table, $col] = explode('.', $column);
            if (! DB::getSchemaBuilder()->hasColumn($table, $col)) {
                $errors[] = "Falta la columna {$column}: correr php artisan migrate.";
            }
        }
        if (! DB::getSchemaBuilder()->hasTable('electo_import_rows')) {
            $errors[] = 'Falta la tabla electo_import_rows: correr php artisan migrate.';
        }
        if (in_array('personas', $partes, true) && ! $this->checkAllows('data_consents_channel_check', PersonasImporter::CONSENT_CHANNEL)) {
            $errors[] = "El CHECK de data_consents.channel no admite '".PersonasImporter::CONSENT_CHANNEL."': aplicar el SQL de docs/migracion-electo.md como postgres.";
        }
        if (in_array('eventos', $partes, true) && ! $this->checkAllows('event_attendees_source_check', ImportContext::ORIGEN)) {
            $errors[] = "El CHECK de event_attendees.source no admite 'electo': correr php artisan migrate.";
        }
        foreach ($partes as $parte) {
            foreach ($this->files($parte) as $table) {
                if (CsvReader::find($this->ctx->dir, $table) === null) {
                    $errors[] = "Falta el archivo {$table}_*.csv para la parte {$parte}.";
                }
            }
        }
        if ($this->ctx->municipiosLoaded() < 1000) {
            $this->ctx->report->aviso('La tabla DIVIPOLA nacional no está cargada (geo:cargar-divipola): las personas de fuera de Santander quedarán sin municipio.');
        }

        return $errors;
    }

    public function run(array $partes): ImportReport
    {
        $ordered = array_values(array_intersect(self::PARTES, $partes));
        $work = function () use ($ordered) {
            foreach ($ordered as $parte) {
                $start = microtime(true);
                $this->ctx->info("Parte {$parte}...");
                (new (self::CLASES[$parte])($this->ctx))->run();
                $this->ctx->analyze(self::ANALYZE[$parte]);
                $this->ctx->report->tiempos[$parte] = round(microtime(true) - $start, 1);
            }
        };

        if (! $this->ctx->dryRun) {
            $work();

            return $this->ctx->report;
        }

        DB::beginTransaction();
        try {
            $work();
        } finally {
            DB::rollBack();
        }

        return $this->ctx->report;
    }

    private function files(string $parte): array
    {
        return match ($parte) {
            'personas' => ['votantes'],
            'contactos' => ['votantecontactos'],
            'mapa' => ['mapapolitico'],
            'eventos' => ['eventos', 'eventoasistentes', 'votantes'],
            'funcionarios' => ['funcionarios_entidades', 'funcionarios'],
            default => [],
        };
    }

    private function checkAllows(string $constraint, string $value): bool
    {
        $definition = DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ? LIMIT 1', [$constraint]);

        return $definition !== null && str_contains($definition, "'{$value}'");
    }
}
