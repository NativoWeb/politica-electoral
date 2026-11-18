<?php

namespace App\Services\Electo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// funcionarios_entidades.csv + funcionarios.csv -> funcionario_entidades + funcionario_contratos (superadmin only).
// The salary column is dropped as soon as the row is read: it is never stored, logged or reported.
class FuncionariosImporter
{
    public const ENTIDAD_ENTIDAD = 'funcionario_entidad';

    public const ENTIDAD_CONTRATO = 'funcionario_contrato';

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        foreach ($this->ctx->batches('funcionarios_entidades') as $rows) {
            $this->ctx->transaction(fn () => $this->entidades($rows));
        }
        foreach ($this->ctx->batches('funcionarios') as $rows) {
            $rows = array_map(function (array $row) {
                unset($row['salario']);

                return $row;
            }, $rows);
            $this->ctx->transaction(fn () => $this->contratos($rows));
        }
        $this->ctx->info('  funcionarios listos');
    }

    private function entidades(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_ENTIDAD, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $lineage = [];
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_ENTIDAD, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $nombre = Normalizer::singleLine($row['nombre'], 200);
            if ($nombre === null) {
                $lineage[] = [$id, null, 'omitido', 'sin_nombre'];

                continue;
            }
            $code = Normalizer::divipola($row['fkmunicipio']);
            $municipio = $this->ctx->municipio($code);
            if ($municipio === null && $code !== null) {
                $this->ctx->report->municipio('funcionarios_entidades', $code);
            }
            $created = Normalizer::dateTime($row['createdat']);
            $created = $created !== null && $created > '1901' ? $created : now()->toDateTimeString();
            $localId = (string) Str::uuid();
            DB::table('funcionario_entidades')->insert([
                'id' => $localId, 'campaign_id' => $this->ctx->campaignId, 'nombre' => $nombre,
                'direccion' => Normalizer::singleLine($row['direccion'], 255), 'telefono' => Normalizer::singleLine($row['telefono'], 50),
                'email' => Normalizer::email($row['email']), 'municipio_id' => $municipio['id'] ?? null,
                'is_active' => trim($row['estado']) === '1', 'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'created_at' => $created, 'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ]);
            $lineage[] = [$id, $localId, 'creado', null];
        }
        $this->ctx->record(self::ENTIDAD_ENTIDAD, $lineage);
    }

    private function contratos(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_CONTRATO, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $lideres = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkvotante']), $rows));
        $entidades = $this->ctx->lineage(self::ENTIDAD_ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkentidad']), $rows));
        $insert = [];
        $lineage = [];
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_CONTRATO, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $liderId = $lideres[Normalizer::int($row['fkvotante'])] ?? null;
            $entidadId = $entidades[Normalizer::int($row['fkentidad'])] ?? null;
            if ($liderId === null || $entidadId === null) {
                $lineage[] = [$id, null, 'omitido', $liderId === null ? 'persona_no_importada' : 'entidad_no_importada'];

                continue;
            }
            [$observacion] = Normalizer::text($row['observacion']);
            $created = Normalizer::dateTime($row['createdat']) ?? now()->toDateTimeString();
            $localId = (string) Str::uuid();
            $insert[] = [
                'id' => $localId, 'campaign_id' => $this->ctx->campaignId, 'lider_id' => $liderId, 'entidad_id' => $entidadId,
                'fecha_inicio' => Normalizer::date($row['fechainicio'])[0], 'fecha_final' => Normalizer::date($row['fechafinal'])[0],
                'fecha_terminacion' => Normalizer::date($row['fechaterminacion'])[0],
                'numero_contrato' => Normalizer::singleLine($row['contrato'], 100),
                'otrosi' => Normalizer::bool($row['otrosi']), 'otrosi_fecha_final' => Normalizer::date($row['otrosifechafinal'])[0],
                'observacion' => $observacion, 'estado' => trim($row['estado']) === '1' ? 'activo' : 'inactivo',
                'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'created_at' => $created, 'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ];
            $lineage[] = [$id, $localId, 'creado', null];
        }
        foreach (array_chunk($insert, 500) as $chunk) {
            DB::table('funcionario_contratos')->insert($chunk);
        }
        $this->ctx->record(self::ENTIDAD_CONTRATO, $lineage);
    }
}
