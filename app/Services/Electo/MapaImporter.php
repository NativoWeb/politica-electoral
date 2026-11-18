<?php

namespace App\Services\Electo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Electo's "mapa político" has two files:
// - mapapolitico.csv: political positions/votes of a person (cargo, partido, aliado, votos, periodo) -> one
//   lider_info_politica row each, the table the ficha already shows as "Información política". Electo sent no catalog
//   for the 16 cargos, the tipo (0-3) or the estado (0/1/2): every row is imported, cargo_politico and estado stay
//   empty (estado is NOT NULL: ''), without interpreting them, and the codes go to migracion_flags.electo_codigos with
//   the fields in migracion_flags.por_completar for the team to complete.
// - lideres.csv: territorial leadership assignments -> lider_municipios_influencia (municipio, with Electo's detalle,
//   period and active flag in the note). The person itself was already created as tipo 'lider' by PersonasImporter;
//   an existing ficha only gets the cargo when empty and the mark electo_lider (its tipo is never changed).
class MapaImporter
{
    public const ENTIDAD_CARGO = 'mapa_politico';

    public const ENTIDAD_ASIGNACION = 'lider_asignacion';

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        foreach ($this->ctx->batches('mapapolitico') as $rows) {
            $this->ctx->transaction(fn () => $this->cargos($rows));
        }
        $this->ctx->info('  mapa político: cargos listos');
        if ($this->ctx->file('lideres', false) !== null) {
            foreach ($this->ctx->batches('lideres') as $rows) {
                $this->ctx->transaction(fn () => $this->asignaciones($rows));
            }
        }
        $this->ctx->info('  mapa político: asignaciones de líder listas');
    }

    private function cargos(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_CARGO, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $lideres = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkvotante']), $rows));
        $partidos = $this->ctx->catalog('partidos_politicos');
        $insert = [];
        $lineage = [];
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_CARGO, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $liderId = $lideres[Normalizer::int($row['fkvotante'])] ?? null;
            if ($liderId === null) {
                $lineage[] = [$id, null, 'omitido', 'persona_no_importada'];

                continue;
            }
            $code = Normalizer::divipola($row['fkmunicipio']);
            $municipio = $this->ctx->municipio($code);
            if ($municipio === null && trim($row['fkmunicipio']) !== '') {
                $this->ctx->report->municipio('mapapolitico', trim($row['fkmunicipio']));
            }
            [$desde] = Normalizer::date($row['fechainicial']);
            [$hasta] = Normalizer::date($row['fechafinal']);
            [$detalle] = Normalizer::text($row['detalle']);
            $partidoCode = trim($row['fkpartido']);
            $partido = $partidos[$partidoCode] ?? null;
            $codes = ['cargo_politico' => trim($row['fkcargopolitico']), 'estado' => trim($row['estado']), 'tipo' => trim($row['tipo']),
                'partido' => $partidoCode, 'municipio' => $municipio === null ? trim($row['fkmunicipio']) : ''];
            $pending = array_values(array_filter(['cargo_politico', 'estado'], fn ($f) => $codes[$f] !== ''));
            if ($partido === null && $partidoCode !== '') {
                $pending[] = 'partido';
            }
            foreach ($pending as $field) {
                $this->ctx->report->porCompletar('lider_info_politica', $field);
            }
            $created = Normalizer::dateTime($row['createdat']) ?? now()->toDateTimeString();
            $localId = (string) Str::uuid();
            $insert[] = [
                'id' => $localId, 'lider_id' => $liderId, 'cargo_politico' => null,
                'departamento' => ImportContext::cut($this->ctx->departamento($row['fkdepto']) ?? $municipio['departamento'] ?? null, 100),
                'municipio' => ImportContext::cut($municipio['name'] ?? null, 100),
                'partido' => $partido === null ? null : ImportContext::cut(mb_strtoupper(Normalizer::singleLine($partido)), 200),
                'aliado' => Normalizer::bool($row['aliado']) ? 'Sí' : 'No',
                'votos' => Normalizer::int($row['votos']) ?? 0,
                'observacion' => $detalle,
                'estado' => '',
                'periodo_desde' => $desde, 'periodo_hasta' => $hasta,
                'migracion_flags' => ImportContext::json([
                    'electo_codigos' => array_filter($codes, fn ($v) => $v !== ''), 'por_completar' => $pending,
                ]),
                'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'created_at' => $created, 'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ];
            $lineage[] = [$id, $localId, 'creado', null];
        }
        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('lider_info_politica')->insert($chunk);
        }
        $this->ctx->record(self::ENTIDAD_CARGO, $lineage);
    }

    private function asignaciones(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD_ASIGNACION, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $lideres = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkvotante']), $rows));
        $lineage = [];
        $now = now();
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null || isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD_ASIGNACION, $id === null ? 'omitido:sin_id' : 'ya_importado');

                continue;
            }
            $liderId = $lideres[Normalizer::int($row['fkvotante'])] ?? null;
            if ($liderId === null) {
                $lineage[] = [$id, null, 'omitido', 'persona_no_importada'];

                continue;
            }
            $detalle = Normalizer::singleLine($row['detalle'], 255);
            $ficha = DB::table('lideres')->where('id', $liderId)->first(['cargo', 'origen', 'migracion_flags']);
            if ($ficha->origen !== ImportContext::ORIGEN) {
                $flags = array_replace(json_decode((string) $ficha->migracion_flags, true) ?: [], ['electo_lider' => true]);
                $update = [];
                if ($detalle !== null && ($ficha->cargo ?? '') === '') {
                    $update['cargo'] = $detalle;
                    $flags['electo_antes'] = array_replace(['cargo' => $ficha->cargo], $flags['electo_antes'] ?? []);
                }
                $update['migracion_flags'] = json_encode($flags, JSON_UNESCAPED_UNICODE);
                DB::table('lideres')->where('id', $liderId)->update($update);
            }

            $code = Normalizer::divipola($row['fkmunicipio']);
            $municipio = $this->ctx->municipio($code);
            if ($municipio === null) {
                if ($code !== null) {
                    $this->ctx->report->municipio('lideres', $code);
                }
                $lineage[] = [$id, $liderId, 'omitido', 'sin_municipio'];

                continue;
            }
            [$desde] = Normalizer::date($row['fechainicial']);
            [$hasta] = Normalizer::date($row['fechafinal']);
            $nota = trim('Asignación de líder en Electo'.($detalle ? ": {$detalle}" : '').($desde ? "; desde {$desde}" : '')
                .($hasta ? " hasta {$hasta}" : '').'; '.(Normalizer::bool($row['activo']) && trim($row['estado']) === '1' ? 'activa' : 'inactiva').'.');
            $inserted = DB::table('lider_municipios_influencia')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'lider_id' => $liderId, 'geographic_unit_id' => $municipio['id'],
                'municipio_name' => $municipio['name'], 'provincia_name' => $municipio['provincia'] ?? $municipio['departamento'],
                'nota' => $nota, 'origen' => ImportContext::ORIGEN, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $lineage[] = $inserted ? [$id, $liderId, 'creado', null] : [$id, $liderId, 'fusionado', 'municipio_ya_asignado'];
        }
        $this->ctx->record(self::ENTIDAD_ASIGNACION, $lineage);
    }
}
