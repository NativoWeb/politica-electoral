<?php

namespace App\Services\Electo;

use App\Models\Lider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// votantes.csv -> lideres. A person whose cédula (digits, no leading zeros) already exists in the campaign is never
// overwritten: only its empty fields are filled and every difference is kept in migracion_flags.contradicciones for
// review. Without a cédula match a new ficha is created; when its name and municipio match an existing ficha it is
// marked "posible_duplicado" (never merged automatically). Minors, deceased and people without municipio are created
// inactive and flagged. Electo's authorization is kept (decision of Marly) and its weak evidence is recorded in
// data_consents with channel 'migracion_electo'. A field that depends on a catalog Electo did not send stays empty,
// with the Electo code in migracion_flags.electo_codigos and the field listed in migracion_flags.por_completar. Rows
// are written with the query builder in bulk, without the Lider model events (comuna assignment needs a barrio, which
// Electo does not give: no catalog).
class PersonasImporter
{
    public const ENTIDAD = 'votante';

    public const TIPO_VOTANTE = Lider::TIPO_VOTANTE;

    public const TIPO_LIDER = 'lider';

    public const SIN_MUNICIPIO = 'Sin municipio';

    public const CONSENT_VERSION = 'electo-migracion-1';

    public const CONSENT_TEXT = 'Autorización registrada en el sistema anterior Electo (sin fecha ni texto)';

    public const CONSENT_CHANNEL = 'migracion_electo';

    // Our field => Electo column, for the catalogs Electo did not send (decision of Marly 2026-11-18: do not wait).
    public const SIN_CATALOGO = ['barrio' => 'fkbarrio', 'nivel_confianza' => 'fkconfianza', 'convenio' => 'fkconvenio', 'tipo_hoja_vida' => 'hvtipo'];

    // Electo codes without a catalog AND without a field of ours: kept for traceability only.
    private const SIN_DESTINO = ['origen_dato' => 'origindata', 'creado_por' => 'fkcreated', 'editado_por' => 'fkupdated'];

    // nivel_confianza is NOT NULL: its "empty" value is the default.
    public const CONFIANZA_VACIA = 'sin_llamar';

    // Fields compared when both sides have a value; free text (dirección, observación, puesto...) is not compared.
    private const COMPARE = ['nombre', 'tipo_documento', 'fecha_nacimiento', 'fecha_expedicion_doc', 'genero', 'estado_civil'];

    private const BOOLEANS = ['militante', 'verificado', 'fallecido', 'empresario', 'reservista', 'funcionario', 'exfuncionario', 'gran_elector', 'autoriza_datos'];

    // Never filled from the generic loop: identity, territory and flags have their own rules.
    private const NOT_FILLED = ['id', 'campaign_id', 'origen', 'origen_id', 'origen_meta', 'migracion_flags', 'tipo', 'is_active', 'created_at',
        'updated_at', 'municipio', 'provincia', 'geographic_unit_id', 'nivel_confianza', 'cedula', ...self::BOOLEANS];

    // normalized cédula => 'e'|'l' (created by the import | local) followed by the ficha id; strings, not arrays, to
    // keep ~130.000 entries small.
    private array $byCedula = [];

    private array $byName = [];

    private array $parents = [];

    private array $children = [];

    private array $electoLideres = [];

    private ?object $consent = null;

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        $this->loadElectoLideres();
        $this->loadExisting();
        $this->consent = $this->consentText();

        $n = 0;
        foreach ($this->ctx->batches('votantes') as $rows) {
            $this->ctx->transaction(fn () => $this->batch($rows));
            $n += count($rows);
            $this->ctx->info("  personas: {$n} leídas");
        }

        $this->ctx->analyze(['lideres', 'electo_import_rows']);
        $this->ctx->transaction(fn () => $this->referentes());
    }

    private function loadElectoLideres(): void
    {
        $path = $this->ctx->file('lideres', false);
        if ($path === null) {
            return;
        }
        foreach (CsvReader::rows($path) as $row) {
            $id = Normalizer::int($row['fkvotante']);
            if ($id !== null && ! isset($this->electoLideres[$id])) {
                $this->electoLideres[$id] = Normalizer::singleLine($row['detalle'], 255);
            }
        }
    }

    // Cédulas of the campaign (including the ones a previous run created) and names of the fichas that existed before.
    private function loadExisting(): void
    {
        $rows = DB::table('lideres')->where('campaign_id', $this->ctx->campaignId)
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'cedula', 'nombre', 'geographic_unit_id', 'origen']);
        foreach ($rows as $r) {
            $key = Normalizer::cedulaKey($r->cedula);
            if ($key !== null) {
                // Two local fichas with the same cédula once normalized: merge into the one stored exactly like ours.
                if (! isset($this->byCedula[$key]) || $r->cedula === $key) {
                    $this->byCedula[$key] = ($r->origen === ImportContext::ORIGEN ? 'e' : 'l').$r->id;
                }
            }
            if ($r->origen !== ImportContext::ORIGEN) {
                $this->byName[Normalizer::personKey($r->nombre).'|'.$r->geographic_unit_id][] = $r->id;
            }
        }
    }

    private function consentText(): object
    {
        $existing = DB::table('consent_texts')->where('campaign_id', $this->ctx->campaignId)->where('version', self::CONSENT_VERSION)->first(['id', 'sha256']);
        if ($existing) {
            return $existing;
        }
        $row = (object) ['id' => (string) Str::uuid(), 'sha256' => hash('sha256', self::CONSENT_TEXT)];
        // Inactive and not approved: it is evidence of the old system, never the text an event or the QR shows.
        DB::table('consent_texts')->insert([
            'id' => $row->id, 'campaign_id' => $this->ctx->campaignId, 'version' => self::CONSENT_VERSION, 'cuerpo' => self::CONSENT_TEXT,
            'sha256' => $row->sha256, 'vigente_desde' => now(), 'is_active' => false, 'approved' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $row;
    }

    private function batch(array $rows): void
    {
        $done = $this->ctx->lineage(self::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['id']), $rows));
        $creates = [];
        $merges = [];
        $lineage = [];
        $consents = [];

        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null) {
                $this->ctx->report->count(self::ENTIDAD, 'omitido', 'sin_id');

                continue;
            }
            [$cedula] = Normalizer::cedula($row['documento'], $row['tipodocumento']);
            $this->parents[$id] = ($cedula ?? '')."\x1f".Normalizer::singleLine($row['nombres'], 150)."\x1f".Normalizer::singleLine($row['apellidos'], 150);
            $parent = Normalizer::int($row['fkidpadre']);
            if ($parent !== null && $parent !== $id) {
                $this->children[$id] = $parent;
            }
            if (isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD, 'ya_importado');

                continue;
            }

            [$rec, $meta, $flags] = $this->normalize($row);
            if ($rec['nombre'] === null) {
                $lineage[] = [$id, null, 'omitido', 'sin_nombre'];

                continue;
            }
            $target = $rec['cedula'] !== null ? ($this->byCedula[$rec['cedula']] ?? null) : null;
            if ($target !== null) {
                $merges[] = [$id, ['id' => substr($target, 1), 'electo' => $target[0] === 'e'], $rec, $meta, $flags];

                continue;
            }

            $localId = (string) Str::uuid();
            $similar = $this->byName[Normalizer::personKey($rec['nombre']).'|'.$rec['geographic_unit_id']] ?? [];
            if ($similar !== []) {
                $flags['posible_duplicado'] = array_slice($similar, 0, 5);
            }
            if ($rec['cedula'] !== null) {
                $this->byCedula[$rec['cedula']] = 'e'.$localId;
            }
            $active = ! (($flags['menor'] ?? false) || ($flags['fallecido'] ?? false) || ($flags['sin_municipio'] ?? false));
            $creates[] = $rec + [
                'id' => $localId, 'campaign_id' => $this->ctx->campaignId, 'origen' => ImportContext::ORIGEN, 'origen_id' => $id,
                'tipo' => $this->tipo($id, $row), 'is_active' => $active,
                'origen_meta' => ImportContext::json($meta), 'migracion_flags' => ImportContext::json($flags),
            ];
            $this->countFlags($flags, 'creado');
            if (! $active) {
                $this->ctx->report->count(self::ENTIDAD, 'creado_inactivo');
            }
            $lineage[] = [$id, $localId, 'creado', null];
            if ($rec['autoriza_datos']) {
                $consents[$localId] = [$rec['cedula'], $rec['created_at']];
            }
        }

        foreach (array_chunk($creates, 400) as $chunk) {
            DB::table('lideres')->insert($chunk);
        }
        foreach ($merges as [$id, $target, $rec, $meta, $flags]) {
            $lineage[] = [$id, $target['id'], 'fusionado', $target['electo'] ? 'cedula_repetida_en_electo' : 'existente'];
            if ($this->merge($id, $target['id'], $rec, $meta, $flags) && $rec['autoriza_datos']) {
                $consents[$target['id']] = [$rec['cedula'], $rec['created_at']];
            }
        }
        $this->ctx->record(self::ENTIDAD, $lineage);
        $this->insertConsents($consents);
    }

    private function tipo(int $id, array $row): string
    {
        return array_key_exists($id, $this->electoLideres) || Normalizer::bool($row['lider']) ? self::TIPO_LIDER : self::TIPO_VOTANTE;
    }

    // [lideres columns, origen_meta, migracion_flags] of one Electo votante. clavegl is never read.
    private function normalize(array $row): array
    {
        $today = $this->ctx->today;
        $flags = [];
        $invalidDates = [];

        [$cedula, $docFlag] = Normalizer::cedula($row['documento'], $row['tipodocumento']);
        if ($docFlag) {
            $flags[$docFlag] = true;
        }
        [$nacimiento, $bad] = Normalizer::birthDate($row['fechanacimiento'], $today);
        if ($bad) {
            $invalidDates[] = 'fecha_nacimiento';
        }
        [$expedicion, $bad] = Normalizer::date($row['docexp']);
        if ($expedicion !== null && ($expedicion > $today->toDateString() || ($nacimiento !== null && $expedicion < $nacimiento))) {
            [$expedicion, $bad] = [null, true];
        }
        if ($bad) {
            $invalidDates[] = 'fecha_expedicion_doc';
        }
        [$hojaVida, $bad] = Normalizer::date($row['hvfechaentregada']);
        if ($bad) {
            $invalidDates[] = 'fecha_registro_hv';
        }
        if ($invalidDates !== []) {
            $flags['fecha_invalida'] = $invalidDates;
        }
        if (Normalizer::isMinor($nacimiento, $today)) {
            $flags['menor'] = true;
        }
        $fallecido = Normalizer::bool($row['fallecido']);
        if ($fallecido) {
            $flags['fallecido'] = true;
        }

        $code = Normalizer::divipola($row['fkmunicipio']);
        $municipio = $this->ctx->municipio($code);
        if ($municipio === null) {
            $flags['sin_municipio'] = true;
            if ($code !== null) {
                $this->ctx->report->municipio('votantes', $code);
            }
        }
        $puestoCode = Normalizer::divipola($row['fkmunpuestovot']);
        [$observacion, $cut] = Normalizer::text($row['observacion']);
        if ($cut) {
            $flags['observacion_recortada'] = true;
        }

        $catalog = fn (string $table, string $field) => $this->ctx->catalog($table)[trim($row[$field])] ?? null;
        $created = Normalizer::dateTime($row['createdat']) ?? now()->toDateTimeString();
        $id = Normalizer::int($row['id']);

        $rec = [
            'nombre' => Normalizer::name($row['nombres'], $row['apellidos']),
            'tipo_documento' => $cedula !== null ? Normalizer::tipoDocumento($row['tipodocumento']) : null,
            'cedula' => $cedula,
            'fecha_expedicion_doc' => $expedicion,
            'genero' => Normalizer::genero($row['genero']),
            'fecha_nacimiento' => $nacimiento,
            'direccion' => Normalizer::singleLine($row['direccion'], 255),
            'geographic_unit_id' => $municipio['id'] ?? null,
            'municipio' => $municipio['name'] ?? self::SIN_MUNICIPIO,
            'provincia' => $municipio['provincia'] ?? null,
            'facebook' => Normalizer::singleLine($row['facebook'], 255),
            'twitter' => Normalizer::singleLine($row['twitter'], 255),
            'instagram' => Normalizer::singleLine($row['instagram'], 255),
            'observacion' => $observacion,
            'profesion' => Normalizer::profesion($catalog('profesiones', 'fkprofesion')),
            'nivel_confianza' => self::CONFIANZA_VACIA,
            'fecha_registro_hv' => $hojaVida,
            'departamento_votacion' => ImportContext::cut($this->ctx->departamento($row['fkdeppuestovot']) ?? Normalizer::singleLine($row['fkdeppuestovot']), 100),
            'municipio_votacion' => ImportContext::cut($this->ctx->municipio($puestoCode)['name'] ?? $puestoCode, 100),
            'puesto_votacion' => Normalizer::singleLine($row['puestovot'], 200),
            'direccion_puesto' => Normalizer::singleLine($row['dirpuestovot'], 300),
            'mesa_votacion' => Normalizer::singleLine($row['mesavot'], 20),
            'estado_civil' => Normalizer::estadoCivil($catalog('estadocivil', 'fkestadocivil')),
            'escolaridad' => Normalizer::escolaridad($catalog('escolaridad', 'fkescolaridad')),
            'cargo' => $this->electoLideres[$id] ?? null,
            'militante' => Normalizer::bool($row['militante']),
            'autoriza_datos' => Normalizer::bool($row['autorizainfo']),
            'verificado' => Normalizer::bool($row['verificado']),
            'fallecido' => $fallecido,
            'empresario' => Normalizer::bool($row['empresario']),
            'reservista' => Normalizer::bool($row['reservista']),
            'funcionario' => Normalizer::bool($row['funcionario']),
            'exfuncionario' => Normalizer::bool($row['exfuncionario']),
            'gran_elector' => Normalizer::bool($row['granelector']),
            'created_at' => $created,
            'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
        ];

        $meta = [
            'tipodocumento' => trim($row['tipodocumento']),
            'documento_original' => $docFlag ? Normalizer::singleLine($row['documento'], 40) : null,
            'municipio_codigo' => $municipio === null ? trim($row['fkmunicipio']) : null,
            'referente' => Normalizer::int($row['fkidpadre']),
            'candidato_ge' => Normalizer::bool($row['candidatoge']) ?: null,
            'lider' => Normalizer::bool($row['lider']) ?: null,
        ];

        // Fields whose Electo catalog never came: our field stays empty, the code is kept and the field is marked
        // "por_completar" so the team finds it with the filters. Code '' or '0' = Electo had no value.
        $codes = [];
        foreach (self::SIN_CATALOGO as $field => $column) {
            $code = trim((string) $row[$column]);
            if ($code !== '' && $code !== '0') {
                $codes[$field] = $code;
                $flags['por_completar'][] = $field;
            }
        }
        // Minors (Ley 1581 art. 7): Electo's authorization is not taken; its value is kept in the flag for review.
        if (($flags['menor'] ?? false) && $rec['autoriza_datos']) {
            $rec['autoriza_datos'] = false;
            $flags['autorizacion_menor_omitida'] = true;
        }
        // Gender: the diagnosis' inference (1 = F, 2 = M) is used, but marked and with the original code.
        if ($rec['genero'] !== null) {
            $flags['genero_inferido'] = true;
        }
        $codes['genero'] = trim($row['genero']);
        foreach (self::SIN_DESTINO as $field => $column) {
            $codes[$field] = trim((string) $row[$column]);
        }
        $flags['electo_codigos'] = array_filter($codes, fn ($v) => $v !== '');

        return [$rec, $meta, $flags];
    }

    // Fills only the empty fields of an existing ficha. Returns whether the authorization ends up true.
    private function merge(int $electoId, string $localId, array $rec, array $meta, array $flags): bool
    {
        $row = DB::table('lideres')->where('id', $localId)->lockForUpdate()->first();
        $updates = [];
        $contradictions = [];

        foreach ($rec as $field => $value) {
            if ($value === null || in_array($field, self::NOT_FILLED, true)) {
                continue;
            }
            $current = $row->{$field} ?? null;
            if ($current === null || $current === '') {
                $updates[$field] = $value;
            } elseif (in_array($field, self::COMPARE, true) && ! $this->same($field, (string) $current, (string) $value)) {
                $contradictions[$field] = $value;
            }
        }
        if ($rec['geographic_unit_id'] !== null) {
            // Without a unit the ficha may still have a municipio typed by hand: only an empty or equal one is completed.
            $sameText = ($row->municipio ?? '') === '' || Normalizer::key($row->municipio) === Normalizer::key($rec['municipio']);
            if ($row->geographic_unit_id === null && $sameText) {
                $updates['geographic_unit_id'] = $rec['geographic_unit_id'];
                $updates['municipio'] = $rec['municipio'];
                if (($row->provincia ?? '') === '') {
                    $updates['provincia'] = $rec['provincia'];
                }
            } elseif ($row->geographic_unit_id === null) {
                $contradictions['municipio'] = $rec['municipio'];
            } elseif ($row->geographic_unit_id !== $rec['geographic_unit_id']) {
                $contradictions['municipio'] = $rec['municipio'];
            }
        }
        foreach (self::BOOLEANS as $field) {
            if ($rec[$field] && ! $row->{$field}) {
                $updates[$field] = true;
            } elseif ($field === 'autoriza_datos' && $row->{$field} && ! $rec[$field] && ! isset($flags['autorizacion_menor_omitida'])) {
                $contradictions[$field] = false;
            }
        }
        // Only what is still empty in the ficha is pending; the inference only counts when it filled the gender.
        $flags['por_completar'] = array_values(array_filter($flags['por_completar'] ?? [], fn ($field) => $field === 'nivel_confianza'
            ? $row->nivel_confianza === self::CONFIANZA_VACIA : ($row->{$field} ?? '') === ''));
        if (! isset($updates['genero'])) {
            unset($flags['genero_inferido']);
        }

        $merged = json_decode((string) $row->migracion_flags, true) ?: [];
        // The previous value of every field filled (empty, false or the default), so the merge can be undone; the
        // first value recorded wins on a re-run.
        $before = [];
        foreach (array_keys($updates) as $field) {
            $before[$field] = $row->{$field};
        }
        $pending = array_values(array_unique([...($merged['por_completar'] ?? []), ...$flags['por_completar']]));
        $codes = array_replace($flags['electo_codigos'] ?? [], $merged['electo_codigos'] ?? []);
        $merged = array_replace($merged, $flags, ['electo_id' => $electoId, 'electo_codigos' => $codes]);
        $merged['por_completar'] = $pending;
        if ($pending === []) {
            unset($merged['por_completar']);
        }
        if ($before !== []) {
            $merged['electo_antes'] = array_replace($before, $merged['electo_antes'] ?? []);
        }
        if (isset($this->electoLideres[$electoId]) || ($meta['lider'] ?? false)) {
            $merged['electo_lider'] = true;
        }
        if ($contradictions !== []) {
            $merged['contradicciones'] = array_replace($merged['contradicciones'] ?? [], $contradictions);
            foreach (array_keys($contradictions) as $field) {
                $this->ctx->report->contradiccion($field);
            }
        }
        if (isset($updates['autoriza_datos'])) {
            $merged['autoriza_datos_desde_electo'] = true;
            $this->ctx->report->count(self::ENTIDAD, 'fusionado.autoriza_datos_desde_electo');
        }
        $updates['migracion_flags'] = json_encode($merged, JSON_UNESCAPED_UNICODE);
        if ($row->origen_meta === null) {
            $updates['origen_meta'] = ImportContext::json($meta);
        }
        $updates['updated_at'] = now();
        DB::table('lideres')->where('id', $localId)->update($updates);
        $this->countFlags($flags, 'fusionado');
        if (count($updates) > 2 + ($row->origen_meta === null ? 1 : 0)) {
            $this->ctx->report->count(self::ENTIDAD, 'fusionado_con_datos_nuevos');
        }

        return (bool) ($updates['autoriza_datos'] ?? $row->autoriza_datos);
    }

    private function same(string $field, string $current, string $value): bool
    {
        return match ($field) {
            'nombre' => Normalizer::personKey($current) === Normalizer::personKey($value),
            'fecha_nacimiento', 'fecha_expedicion_doc' => substr($current, 0, 10) === $value,
            default => Normalizer::key($current) === Normalizer::key($value),
        };
    }

    private function countFlags(array $flags, string $prefix): void
    {
        foreach ($flags as $flag => $value) {
            if ($flag === 'electo_codigos') {
                continue;
            }
            if ($flag === 'por_completar') {
                foreach ($value as $field) {
                    $this->ctx->report->porCompletar('lideres', $field);
                }
            } elseif ($flag === 'fecha_invalida') {
                foreach ($value as $field) {
                    $this->ctx->report->flag("{$prefix}.fecha_invalida.{$field}");
                }
            } else {
                $this->ctx->report->flag("{$prefix}.{$flag}");
            }
        }
    }

    // One data_consents row per ficha with Electo's authorization (append-only; skipped if this text already has one).
    private function insertConsents(array $consents): void
    {
        if ($consents === []) {
            return;
        }
        $already = DB::table('data_consents')->where('consent_text_id', $this->consent->id)
            ->whereIn('lider_id', array_keys($consents))->pluck('lider_id')->flip()->all();
        $insert = [];
        foreach ($consents as $liderId => [$cedula, $createdAt]) {
            if (isset($already[$liderId])) {
                continue;
            }
            $insert[] = [
                'id' => (string) Str::uuid(), 'campaign_id' => $this->ctx->campaignId, 'consent_text_id' => $this->consent->id,
                'text_sha256' => $this->consent->sha256, 'cedula' => $cedula !== null && strlen($cedula) <= 20 ? $cedula : null,
                'lider_id' => $liderId, 'event_id' => null, 'attendee_id' => null, 'channel' => self::CONSENT_CHANNEL,
                'ip' => null, 'user_agent' => null, 'accepted_at' => $createdAt,
            ];
        }
        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('data_consents')->insert($chunk);
        }
        $this->ctx->report->count('consentimiento', 'creado', null, count($insert));
    }

    // Second pass: the referente (fkidpadre) as document and name, only where the ficha has none.
    private function referentes(): void
    {
        $total = 0;
        foreach (array_chunk($this->children, 2000, true) as $chunk) {
            $local = $this->ctx->lineage(self::ENTIDAD, array_keys($chunk));
            $values = [];
            $bindings = [];
            foreach ($chunk as $child => $parent) {
                if (! isset($local[$child], $this->parents[$parent])) {
                    continue;
                }
                [$doc, $nombres, $apellidos] = explode("\x1f", $this->parents[$parent]);
                $values[] = '(?::uuid, ?, ?, ?)';
                array_push($bindings, $local[$child], $doc !== '' ? mb_substr($doc, 0, 20) : null, $nombres ?: null, $apellidos ?: null);
            }
            if ($values === []) {
                continue;
            }
            $total += DB::update('UPDATE lideres l SET referente_documento = v.doc, referente_nombre = v.nom, referente_apellido = v.ape, updated_at = now()
                FROM (VALUES '.implode(', ', $values).') AS v(id, doc, nom, ape)
                WHERE l.id = v.id AND coalesce(l.referente_documento, \'\') = \'\' AND coalesce(l.referente_nombre, \'\') = \'\'', $bindings);
        }
        $this->ctx->report->count('referente', 'asignado', null, $total);
    }
}
