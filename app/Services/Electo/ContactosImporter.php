<?php

namespace App\Services\Electo;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// votantecontactos.csv -> lider_contactos (with the WhatsApp mark). Phones are digits without the 57 prefix, emails
// lowercase and valid; a value the ficha already has (same type of contact) is not repeated, and only one contact per
// type is principal. Afterwards, in SQL: the principal phone and email fill lideres.telefono / email when empty (the
// columns Mapa Político and the agent read), and an existing ficha whose phone or email is not among Electo's gets a
// contradiction for review. Nothing is overwritten.
class ContactosImporter
{
    public const ENTIDAD = 'contacto';

    private const TIPOS = ['1' => 'celular', '2' => 'fijo', '3' => 'email'];

    public function __construct(private readonly ImportContext $ctx) {}

    public function run(): void
    {
        $n = 0;
        foreach ($this->ctx->batches('votantecontactos') as $rows) {
            $this->ctx->transaction(fn () => $this->batch($rows));
            $n += count($rows);
            $this->ctx->info("  contactos: {$n} leídos");
        }
        $this->ctx->analyze(['lider_contactos', 'lideres', 'electo_import_rows']);
        $this->ctx->transaction(fn () => $this->fillFicha());
    }

    private function batch(array $rows): void
    {
        $ids = array_map(fn ($r) => Normalizer::int($r['id']), $rows);
        $done = $this->ctx->lineage(self::ENTIDAD, $ids);
        $lideres = $this->ctx->lineage(PersonasImporter::ENTIDAD, array_map(fn ($r) => Normalizer::int($r['fkvotante']), $rows));
        [$seen, $principal] = $this->existing(array_values(array_unique($lideres)));

        $insert = [];
        $lineage = [];
        $now = now();
        foreach ($rows as $row) {
            $id = Normalizer::int($row['id']);
            if ($id === null) {
                $this->ctx->report->count(self::ENTIDAD, 'omitido', 'sin_id');

                continue;
            }
            if (isset($done[$id])) {
                $this->ctx->report->count(self::ENTIDAD, 'ya_importado');

                continue;
            }
            $liderId = $lideres[Normalizer::int($row['fkvotante'])] ?? null;
            if ($liderId === null) {
                $lineage[] = [$id, null, 'omitido', 'persona_no_importada'];

                continue;
            }
            [$tipo, $valor, $motivo] = $this->value($row);
            if ($motivo !== null) {
                $lineage[] = [$id, null, 'omitido', $motivo];

                continue;
            }
            $kind = $tipo === 'email' ? 'email' : 'telefono';
            if (isset($seen[$liderId][$kind][$valor])) {
                // Equal to what the ficha already had: proof that the two systems agree (no contradiction).
                $lineage[] = $seen[$liderId][$kind][$valor] === 'local'
                    ? [$id, $liderId, 'omitido', "ya_existe_{$kind}"]
                    : [$id, null, 'omitido', 'repetido'];

                continue;
            }
            $seen[$liderId][$kind][$valor] = 'electo';
            $isPrincipal = Normalizer::bool($row['principal']) && ! isset($principal[$liderId][$tipo]);
            if ($isPrincipal) {
                $principal[$liderId][$tipo] = true;
            }
            $localId = (string) Str::uuid();
            $created = Normalizer::dateTime($row['createdat']) ?? $now->toDateTimeString();
            $insert[] = [
                'id' => $localId, 'lider_id' => $liderId, 'tipo' => $tipo, 'valor' => $valor, 'principal' => $isPrincipal,
                'estado' => trim($row['estado']) === '1' ? 'activo' : 'inactivo', 'whatsapp' => Normalizer::bool($row['whatsapp']),
                'origen' => ImportContext::ORIGEN, 'origen_id' => $id, 'created_at' => $created,
                'updated_at' => Normalizer::dateTime($row['updatedat']) ?? $created,
            ];
            $lineage[] = [$id, $localId, 'creado', null];
        }

        foreach (array_chunk($insert, 1000) as $chunk) {
            DB::table('lider_contactos')->insert($chunk);
        }
        $this->ctx->record(self::ENTIDAD, $lineage);
    }

    // [tipo, valor, motivo de omisión]. Type 0 has no catalog: it is inferred from the value.
    private function value(array $row): array
    {
        $raw = trim((string) $row['contacto']);
        if ($raw === '') {
            return [null, null, 'vacio'];
        }
        $tipo = self::TIPOS[trim($row['tipocontacto'])] ?? (str_contains($raw, '@') ? 'email' : null);
        if ($tipo === 'email') {
            $email = Normalizer::email($raw);

            return $email === null ? [null, null, 'email_invalido'] : ['email', $email, null];
        }
        $phone = Normalizer::phone($raw);
        if ($phone === null) {
            return [null, null, 'telefono_invalido'];
        }
        $tipo ??= Normalizer::isMobile($phone) ? 'celular' : 'fijo';

        return [$tipo, $phone, null];
    }

    // Values each ficha already has (contacts and its telefono/email columns) and the types with a principal.
    private function existing(array $liderIds): array
    {
        $seen = [];
        $principal = [];
        foreach (array_chunk($liderIds, 5000) as $chunk) {
            foreach (DB::table('lider_contactos')->whereIn('lider_id', $chunk)->get(['lider_id', 'tipo', 'valor', 'principal', 'origen']) as $c) {
                $isEmail = str_contains((string) $c->valor, '@');
                $value = $isEmail ? Normalizer::email($c->valor) : Normalizer::phone($c->valor);
                if ($value !== null) {
                    $seen[$c->lider_id][$isEmail ? 'email' : 'telefono'][$value] ??= $c->origen === ImportContext::ORIGEN ? 'electo' : 'local';
                }
                if ($c->principal) {
                    $principal[$c->lider_id][strtolower($c->tipo)] = true;
                }
            }
            foreach (DB::table('lideres')->whereIn('id', $chunk)->get(['id', 'telefono', 'email', 'origen', 'migracion_flags']) as $l) {
                // A value this importer copied from Electo (new ficha, or "electo_llenado" on an existing one) is not local.
                $filled = $l->origen === ImportContext::ORIGEN ? ['telefono' => true, 'email' => true]
                    : (json_decode((string) $l->migracion_flags, true)['electo_llenado'] ?? []);
                if (($phone = Normalizer::phone($l->telefono)) && ! isset($filled['telefono'])) {
                    $seen[$l->id]['telefono'][$phone] = 'local';
                }
                if (($email = Normalizer::email($l->email)) && ! isset($filled['email'])) {
                    $seen[$l->id]['email'][$email] = 'local';
                }
            }
        }

        return [$seen, $principal];
    }

    private function fillFicha(): void
    {
        $campaign = $this->ctx->campaignId;

        // First the contradictions, while telefono/email still hold only what the ficha had: an existing ficha (origen
        // NULL) that has a value of that kind, gets Electo values of that kind, and none of them matched what it had.
        foreach (['telefono' => "IN ('celular', 'fijo')", 'email' => "= 'email'"] as $field => $types) {
            $local = $field === 'telefono' ? "coalesce(l.telefono, '') <> ''" : "coalesce(l.email, '') <> ''";
            $n = DB::update("UPDATE lideres l SET migracion_flags = coalesce(l.migracion_flags, '{}'::jsonb)
                    || jsonb_build_object('contradicciones', coalesce(l.migracion_flags->'contradicciones', '{}'::jsonb) || jsonb_build_object('{$field}', p.valor))
                FROM (SELECT DISTINCT ON (lider_id) lider_id, valor FROM lider_contactos
                      WHERE origen = 'electo' AND tipo {$types} ORDER BY lider_id, principal DESC, origen_id) p
                WHERE l.id = p.lider_id AND l.campaign_id = ? AND l.origen IS NULL
                  AND (l.migracion_flags->'contradicciones'->'{$field}') IS NULL
                  AND ({$local} OR EXISTS (SELECT 1 FROM lider_contactos c WHERE c.lider_id = l.id AND c.origen IS NULL AND c.tipo {$types}))
                  AND NOT EXISTS (SELECT 1 FROM electo_import_rows r WHERE r.entidad = 'contacto' AND r.local_id = l.id AND r.motivo = 'ya_existe_{$field}')
                  AND (l.migracion_flags->'electo_llenado'->'{$field}') IS NULL", [$campaign]);
            $this->ctx->report->contradiccion($field, $n);
        }

        // Then fill the empty telefono/email with Electo's principal (marked, so a re-run does not take it as local).
        foreach (['telefono' => "tipo IN ('celular', 'fijo') AND estado = 'activo' ORDER BY lider_id, principal DESC, (tipo = 'celular') DESC, origen_id",
            'email' => "tipo = 'email' AND estado = 'activo' ORDER BY lider_id, principal DESC, origen_id"] as $field => $where) {
            $n = DB::update("UPDATE lideres l SET {$field} = c.valor, updated_at = now(),
                    migracion_flags = coalesce(l.migracion_flags, '{}'::jsonb)
                        || jsonb_build_object('electo_llenado', coalesce(l.migracion_flags->'electo_llenado', '{}'::jsonb) || jsonb_build_object('{$field}', true))
                FROM (SELECT DISTINCT ON (lider_id) lider_id, valor FROM lider_contactos WHERE origen = 'electo' AND {$where}) c
                WHERE l.id = c.lider_id AND l.campaign_id = ? AND l.origen IS NULL AND coalesce(l.{$field}, '') = ''", [$campaign]);
            $created = DB::update("UPDATE lideres l SET {$field} = c.valor
                FROM (SELECT DISTINCT ON (lider_id) lider_id, valor FROM lider_contactos WHERE origen = 'electo' AND {$where}) c
                WHERE l.id = c.lider_id AND l.campaign_id = ? AND l.origen = 'electo' AND coalesce(l.{$field}, '') = ''", [$campaign]);
            $this->ctx->report->count('ficha', "{$field}_llenado_existente", null, $n);
            $this->ctx->report->count('ficha', "{$field}_llenado_nueva", null, $created);
        }
    }
}
