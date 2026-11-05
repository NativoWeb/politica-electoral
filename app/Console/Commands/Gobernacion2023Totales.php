<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PoliticalOrganization;
use App\Services\Import\TextNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The 'Gobernación Santander 2023' contest had one department-level result per candidate with made-up values (no
// source; the GOBERNACION sheet of the Excel only has potencial) and short names taken from the sheet header ("FERLEY
// PARRA" is LUIS FERLEY SIERRA JAIMES). This loads the official Santander totals (preconteo 100%, boletín 51), the
// blank/null/unmarked votes and turnout as a department-level turnout_metrics row, the full candidate names (the short
// name stays as an alias) and each candidate's aval. Only that contest is touched; votes by municipio are not loaded.
// With --dry-run it only reads (PostgreSQL: read-only transaction).
class Gobernacion2023Totales extends Command
{
    protected $signature = 'data:gobernacion-2023-totales {--dry-run : Solo muestra el plan, no guarda nada}';

    protected $description = 'Load the official Santander totals of Gobernación 2023 (preconteo 100%, boletín 51) and fix the candidate names';

    private const AUDIT_COMMAND = 'data:gobernacion-2023-totales';

    public const SOURCE_URL = 'https://portales.vanguardia.com/resultados-elecciones/locales2023/gobernacion_alcaldia';

    public const SOURCE_NAME = 'Gobernación Santander 2023 - Registraduría preconteo 100% (5.856/5.856 mesas), boletín 51, 29-oct-2023 21:05 (vía Vanguardia)';

    // [nombre corto (importado), nombre completo, nombres, apellidos, votos, % de la fuente, aval en la fuente,
    //  organización a crear si no existe, claves de organizaciones existentes (nombre o alias normalizado)]
    public const CANDIDATES = [
        ['JUVENAL DIAZ', 'Juvenal Diaz Mateus', 'Juvenal', 'Diaz Mateus', 423130, '38.97',
            'GENERAL JUVENAL GOBERNADOR', 'General Juvenal Gobernador', ['GENERAL JUVENAL GOBERNADOR']],
        ['HECTOR MANTILLA', 'Hector Guillermo Mantilla Rueda', 'Hector Guillermo', 'Mantilla Rueda', 208781, '19.23',
            'COALICION POR EL DESARROLLO DE SANTANDER', 'Coalicion Por El Desarrollo De Santander', ['COALICION POR EL DESARROLLO DE SANTANDER']],
        ['FERLEY PARRA', 'Luis Ferley Sierra Jaimes', 'Luis Ferley', 'Sierra Jaimes', 181399, '16.70',
            'DIGNIDAD SANTANDEREANA', 'Dignidad Santandereana', ['DIGNIDAD SANTANDEREANA']],
        ['RODOLFO HERNANDEZ', 'Rodolfo Hernandez Suarez', 'Rodolfo', 'Hernandez Suarez', 135183, '12.45',
            'PARTIDO LIGA GOBERNANTES ANTICORRUPCIÓN - LIGA', 'Liga', ['PARTIDO LIGA GOBERNANTES ANTICORRUPCION - LIGA', 'LIGA GOBERNANTES ANTICORRUPCION', 'LIGA']],
        ['JULIAN SILVA', 'Julian Fernando Silva Cala', 'Julian Fernando', 'Silva Cala', 26532, '2.44',
            'PARTIDO NUEVO LIBERALISMO', 'Nuevo Liberalismo', ['PARTIDO NUEVO LIBERALISMO', 'NUEVO LIBERALISMO']],
        ['LUIS RANGEL', 'Luis Alfredo Rangel Villarreal', 'Luis Alfredo', 'Rangel Villarreal', 11125, '1.02',
            'MOVIMIENTO POLÍTICO COLOMBIA HUMANA', 'Colombia Humana', ['MOVIMIENTO POLITICO COLOMBIA HUMANA', 'COLOMBIA HUMANA']],
        ['JOSE DOMINGO CORTES', 'Jose Domingo Cortes Torres', 'Jose Domingo', 'Cortes Torres', 10868, '1.00',
            'NUEVA FUERZA DEMOCRÁTICA', 'Nueva Fuerza Democratica', ['NUEVA FUERZA DEMOCRATICA', 'PARTIDO NUEVA FUERZA DEMOCRATICA']],
        ['LUIS QUINONES', 'Luis Mauricio Quiñones Amaya', 'Luis Mauricio', 'Quiñones Amaya', 8493, '0.78',
            'INDEPENDIENTES', 'Independientes', ['INDEPENDIENTES', 'INDEPENDIENTE']],
        ['JUAN TORRES', 'Juan Torres Ortega', 'Juan', 'Torres Ortega', 3502, '0.32',
            'PARTIDO ECOLOGISTA COLOMBIANO', 'Ecologista Colombiano', ['PARTIDO ECOLOGISTA COLOMBIANO', 'ECOLOGISTA COLOMBIANO']],
    ];

    public const TURNOUT = [
        'electoral_potential' => 1828912,
        'total_voters' => 1168968,
        'valid_votes' => 1085625,
        'blank_votes' => 76612,
        'null_votes' => 19718,
        'unmarked_votes' => 63625,
        'participation_pct' => '63.91',
        'raw_potential' => '1.828.912',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();
        try {
            if ($dryRun && DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            $plan = $this->plan();
            if ($plan === null) {
                DB::rollBack();

                return self::FAILURE;
            }

            $this->printPlan($plan);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('DRY-RUN: no se guardó nada.');

                return self::SUCCESS;
            }

            if ($this->changeCount($plan) === 0) {
                DB::rollBack();
                $this->info('Nada que corregir.');

                return self::SUCCESS;
            }

            $this->apply($plan);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        if (collect($plan['orgs'])->contains('action', 'crear')) {
            Cache::forget('all_partidos');
        }
        $this->info('Corrección aplicada.');

        return self::SUCCESS;
    }

    private function plan(): ?array
    {
        $officeId = DB::table('offices')->where('name', 'Gobernación')->value('id');
        $eventId = DB::table('electoral_events')->where('name', 'Elecciones territoriales 2023')->value('id');
        $santander = DB::table('geographic_units')->where('type', 'department')->where('canonical_name', 'Santander')->value('id');
        if (! $officeId || ! $eventId || ! $santander) {
            $this->error('No se encontró la oficina Gobernación, el evento "Elecciones territoriales 2023" o el departamento Santander.');

            return null;
        }

        $contests = DB::table('contests')->where('office_id', $officeId)->where('electoral_event_id', $eventId)
            ->where('geographic_unit_id', $santander)->pluck('id');
        if ($contests->count() !== 1) {
            $this->error("Se esperaba 1 contienda de Gobernación Santander 2023 y hay {$contests->count()}.");

            return null;
        }
        $contestId = $contests->first();

        $candidacies = DB::table('candidacies as c')->join('persons as p', 'p.id', '=', 'c.person_id')
            ->where('c.contest_id', $contestId)
            ->get(['c.id as candidacy_id', 'p.id as person_id', 'p.first_name', 'p.last_name', 'p.full_name', 'p.normalized_name']);
        $aliases = DB::table('person_aliases')->whereIn('person_id', $candidacies->pluck('person_id'))->get(['person_id', 'normalized_alias']);

        $orgIndex = $this->orgIndex();
        $sourceId = DB::table('source_files')->where('storage_path', self::SOURCE_URL)->value('id');
        $rows = [];
        $orgs = [];
        $matched = [];

        foreach (self::CANDIDATES as $ranking => [$short, $full, $first, $last, $votes, $pct, $avalRaw, $orgName, $orgKeys]) {
            $keys = [self::key($short), self::key($full)];
            $hits = $candidacies->filter(fn ($c) => in_array(self::key((string) $c->normalized_name), $keys, true)
                || in_array(self::key((string) $c->full_name), $keys, true)
                || $aliases->where('person_id', $c->person_id)->contains(fn ($a) => in_array(self::key((string) $a->normalized_alias), $keys, true)));
            if ($hits->count() !== 1) {
                $this->error("{$full}: se esperaba 1 candidatura en la contienda y hay {$hits->count()}.");

                return null;
            }
            $cand = $hits->first();
            $matched[] = $cand->candidacy_id;

            $results = DB::table('electoral_results')->where('candidacy_id', $cand->candidacy_id)
                ->get(['id', 'contest_id', 'grain', 'metric_type', 'geographic_unit_id', 'value', 'raw_value', 'percentage', 'ranking', 'source_id', 'status']);
            $nonDept = $results->filter(fn ($r) => $r->grain !== 'department' || $r->geographic_unit_id !== $santander || $r->contest_id !== $contestId);
            if ($nonDept->isNotEmpty() || $results->count() > 1) {
                $this->error("{$full}: tiene {$results->count()} filas de resultados ({$nonDept->count()} fuera del nivel departamento); se esperaba como máximo 1 de departamento. No se toca nada.");

                return null;
            }
            $result = $results->first();

            $endorsements = DB::table('candidacy_endorsements')->where('candidacy_id', $cand->candidacy_id)->where('is_primary', true)->get(['id', 'organization_id']);
            if ($endorsements->count() > 1) {
                $this->error("{$full}: tiene {$endorsements->count()} avales primarios.");

                return null;
            }
            $endorsement = $endorsements->first();

            $orgKey = self::key($orgName);
            $orgs[$orgKey] ??= $this->planOrg($orgName, $avalRaw, $orgKeys, $orgIndex);
            $org = $orgs[$orgKey];

            $person = ['first_name' => $first, 'last_name' => $last, 'full_name' => $full, 'normalized_name' => TextNormalizer::normalizeName($full)];
            $personChanges = array_keys(array_filter($person, fn ($v, $k) => (string) $cand->{$k} !== $v, ARRAY_FILTER_USE_BOTH));
            $shortKey = self::key($short);
            $aliasMissing = ! $aliases->where('person_id', $cand->person_id)->contains(fn ($a) => self::key((string) $a->normalized_alias) === $shortKey);

            $newResult = [
                'value' => $votes, 'raw_value' => number_format($votes, 0, ',', '.'), 'percentage' => $pct,
                'ranking' => $ranking + 1, 'status' => 'validated',
            ];
            $resultChanged = $result === null || $sourceId === null || $result->source_id !== $sourceId || $result->metric_type !== 'votes'
                || (int) $result->value !== $votes || $result->raw_value !== $newResult['raw_value'] || (int) $result->ranking !== $ranking + 1
                || $result->status !== 'validated' || $result->percentage === null || (float) $result->percentage !== (float) $pct;

            $rows[] = [
                'candidacy_id' => $cand->candidacy_id,
                'person_id' => $cand->person_id,
                'contest_id' => $contestId,
                'santander' => $santander,
                'antes_nombre' => $cand->full_name,
                'despues_nombre' => $full,
                'person' => $person,
                'person_changes' => $personChanges,
                'alias_missing' => $aliasMissing,
                'short' => $short,
                'result' => $result,
                'new_result' => $newResult,
                'result_changed' => $resultChanged,
                'endorsement' => $endorsement,
                'org_key' => $orgKey,
                'org_before' => $endorsement?->organization_id ? DB::table('political_organizations')->where('id', $endorsement->organization_id)->value('canonical_name') : null,
                'endorsement_changed' => $endorsement === null || $org['id'] === null || $endorsement->organization_id !== $org['id'],
            ];
        }

        $others = $candidacies->pluck('candidacy_id')->diff($matched);
        if ($others->isNotEmpty()) {
            $this->error("La contienda tiene {$others->count()} candidatura(s) que no están en la fuente oficial; no se toca nada.");

            return null;
        }

        $turnout = DB::table('turnout_metrics')->where('contest_id', $contestId)->where('grain', 'department')->get();
        if ($turnout->count() > 1) {
            $this->error("Hay {$turnout->count()} filas de participación a nivel departamento para la contienda.");

            return null;
        }
        $turnout = $turnout->first();
        $turnoutChanged = $turnout === null || $sourceId === null || $turnout->source_id !== $sourceId || $turnout->geographic_unit_id !== $santander
            || collect(self::TURNOUT)->contains(fn ($v, $k) => $k === 'participation_pct'
                ? $turnout->{$k} === null || (float) $turnout->{$k} !== (float) $v
                : (string) $turnout->{$k} !== (string) $v);

        return [
            'contest_id' => $contestId,
            'event_id' => $eventId,
            'santander' => $santander,
            'source_id' => $sourceId,
            'rows' => $rows,
            'orgs' => $orgs,
            'turnout' => $turnout,
            'turnout_changed' => $turnoutChanged,
        ];
    }

    private function planOrg(string $name, string $avalRaw, array $keys, array $index): array
    {
        foreach ($keys as $key) {
            if (isset($index[self::key($key)])) {
                $org = $index[self::key($key)];

                return ['id' => $org->id, 'nombre' => $org->canonical_name, 'action' => 'reusar', 'aval' => $avalRaw,
                    'alias_missing' => ! in_array(self::key($avalRaw), $org->keys, true)];
            }
        }

        return ['id' => null, 'nombre' => $name, 'action' => 'crear', 'aval' => $avalRaw, 'alias_missing' => self::key($avalRaw) !== self::key($name)];
    }

    // key => organization (canonical names win over aliases), only organizations not merged into another.
    private function orgIndex(): array
    {
        $orgs = DB::table('political_organizations')->whereNull('merged_into_id')->orderBy('created_at')->get(['id', 'canonical_name']);
        $aliases = DB::table('organization_aliases')->whereIn('organization_id', $orgs->pluck('id'))->get(['organization_id', 'normalized_alias']);

        $index = [];
        foreach ($orgs as $org) {
            $org->keys = array_merge([self::key($org->canonical_name)], $aliases->where('organization_id', $org->id)
                ->map(fn ($a) => self::key((string) $a->normalized_alias))->values()->all());
            $index[$org->keys[0]] ??= $org;
        }
        foreach ($orgs as $org) {
            foreach (array_slice($org->keys, 1) as $key) {
                $index[$key] ??= $org;
            }
        }

        return $index;
    }

    private function apply(array $plan): void
    {
        $now = now();

        $sourceId = $plan['source_id'] ?? (string) Str::uuid();
        if ($plan['source_id'] === null) {
            DB::table('source_files')->insert([
                'id' => $sourceId, 'original_name' => self::SOURCE_NAME, 'storage_path' => self::SOURCE_URL,
                'mime_type' => 'text/html', 'file_type' => 'other', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $orgIds = [];
        foreach ($plan['orgs'] as $key => $org) {
            $id = $org['id'];
            if ($org['action'] === 'crear') {
                $id = (string) Str::uuid();
                DB::table('political_organizations')->insert([
                    'id' => $id, 'type' => 'party', 'canonical_name' => PoliticalOrganization::upperName($org['nombre']), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit('DATA_GOBERNACION_2023_ORGANIZACION_CREADA', 'political_organization', $id, ['aval_fuente' => $org['aval']]);
            }
            if ($org['alias_missing']) {
                DB::table('organization_aliases')->insert([
                    'id' => (string) Str::uuid(), 'organization_id' => $id, 'raw_alias' => $org['aval'],
                    'normalized_alias' => self::key($org['aval']), 'source_id' => $sourceId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $orgIds[$key] = $id;
        }

        foreach ($plan['rows'] as $row) {
            if ($row['person_changes']) {
                DB::table('persons')->where('id', $row['person_id'])->update($row['person'] + ['updated_at' => $now]);
            }
            if ($row['alias_missing']) {
                DB::table('person_aliases')->insert([
                    'id' => (string) Str::uuid(), 'person_id' => $row['person_id'], 'alias_name' => $row['short'],
                    'normalized_alias' => TextNormalizer::normalizeName($row['short']), 'alias_type' => 'electoral', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $orgId = $orgIds[$row['org_key']];
            if ($row['endorsement'] === null) {
                DB::table('candidacy_endorsements')->insert([
                    'id' => (string) Str::uuid(), 'candidacy_id' => $row['candidacy_id'], 'organization_id' => $orgId,
                    'endorsement_type' => 'aval', 'is_primary' => true, 'source_id' => $sourceId, 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif ($row['endorsement']->organization_id !== $orgId) {
                DB::table('candidacy_endorsements')->where('id', $row['endorsement']->id)
                    ->update(['organization_id' => $orgId, 'source_id' => $sourceId, 'updated_at' => $now]);
            }

            $values = $row['new_result'] + ['metric_type' => 'votes', 'grain' => 'department', 'geographic_unit_id' => $row['santander'], 'source_id' => $sourceId];
            if ($row['result'] === null) {
                DB::table('electoral_results')->insert($values + [
                    'id' => (string) Str::uuid(), 'contest_id' => $row['contest_id'], 'candidacy_id' => $row['candidacy_id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            } elseif ($row['result_changed']) {
                DB::table('electoral_results')->where('id', $row['result']->id)->update($values + ['updated_at' => $now]);
            }

            if ($row['person_changes'] || $row['alias_missing'] || $row['endorsement_changed'] || $row['result_changed']) {
                $this->audit('DATA_GOBERNACION_2023_CANDIDATURA', 'candidacy', $row['candidacy_id'], [
                    'person_id' => $row['person_id'],
                    'campos_persona' => $row['person_changes'],
                    'alias_corto_agregado' => $row['alias_missing'],
                    'votos_antes' => $row['result']?->value,
                    'votos_despues' => $row['new_result']['value'],
                    'organizacion_antes' => $row['endorsement']?->organization_id,
                    'organizacion_despues' => $orgId,
                ]);
            }
        }

        if ($plan['turnout_changed']) {
            $values = self::TURNOUT + ['electoral_event_id' => $plan['event_id'], 'geographic_unit_id' => $plan['santander'], 'source_id' => $sourceId, 'updated_at' => $now];
            if ($plan['turnout'] === null) {
                DB::table('turnout_metrics')->insert($values + [
                    'id' => (string) Str::uuid(), 'contest_id' => $plan['contest_id'], 'grain' => 'department', 'created_at' => $now,
                ]);
            } else {
                DB::table('turnout_metrics')->where('id', $plan['turnout']->id)->update($values);
            }
        }

        $this->audit('DATA_GOBERNACION_2023_TOTALES', 'contest', $plan['contest_id'], [
            'fuente' => self::SOURCE_URL,
            'source_id' => $sourceId,
            'participacion_actualizada' => $plan['turnout_changed'],
        ] + $this->counts($plan));
    }

    private function printPlan(array $plan): void
    {
        $this->info('Candidatos (contienda Gobernación Santander 2023, nivel departamento):');
        $this->table(
            ['Antes', 'Después', 'Votos antes', 'Votos después', '% fuente', 'Aval antes', 'Aval después', 'Cambios'],
            collect($plan['rows'])->map(fn ($r) => [
                $r['antes_nombre'],
                $r['despues_nombre'],
                $r['result']?->value === null ? '(sin fila)' : number_format($r['result']->value, 0, ',', '.'),
                $r['new_result']['raw_value'],
                str_replace('.', ',', $r['new_result']['percentage']).'%',
                $r['org_before'] ?? '(sin aval)',
                $plan['orgs'][$r['org_key']]['nombre'],
                collect([
                    $r['person_changes'] ? 'nombre' : null, $r['alias_missing'] ? 'alias' : null,
                    $r['result_changed'] ? 'votos' : null, $r['endorsement_changed'] ? 'aval' : null,
                ])->filter()->implode(', ') ?: '-',
            ])->all()
        );

        $this->info('Organizaciones (avales):');
        $this->table(['Aval en la fuente', 'Organización', 'Acción', 'Alias nuevo'], collect($plan['orgs'])->map(fn ($o) => [
            $o['aval'], $o['nombre'], $o['action'], $o['alias_missing'] ? 'sí' : 'no',
        ])->values()->all());

        $before = $plan['turnout'];
        $this->info('Participación (turnout_metrics, nivel departamento):');
        $this->table(['Campo', 'Antes', 'Después'], collect(self::TURNOUT)->except('raw_potential')->map(fn ($v, $k) => [
            $k, $before?->{$k} ?? '(sin fila)', $v,
        ])->values()->all());

        $this->info('Fuente: '.self::SOURCE_NAME.' — '.self::SOURCE_URL.($plan['source_id'] ? ' (ya registrada)' : ' (se registrará en source_files)'));
        $this->info('Resumen: '.collect($this->counts($plan))->map(fn ($v, $k) => "{$k}={$v}")->implode(', '));
    }

    private function counts(array $plan): array
    {
        $rows = collect($plan['rows']);

        return [
            'personas_renombradas' => $rows->filter(fn ($r) => $r['person_changes'])->count(),
            'alias_agregados' => $rows->where('alias_missing', true)->count(),
            'resultados_actualizados' => $rows->where('result_changed', true)->count(),
            'avales_actualizados' => $rows->where('endorsement_changed', true)->count(),
            'organizaciones_creadas' => collect($plan['orgs'])->where('action', 'crear')->count(),
            'participacion' => $plan['turnout_changed'] ? 1 : 0,
        ];
    }

    private function changeCount(array $plan): int
    {
        return array_sum($this->counts($plan)) + collect($plan['orgs'])->where('alias_missing', true)->count() + ($plan['source_id'] === null ? 1 : 0);
    }

    private function audit(string $action, ?string $entityType, ?string $entityId, array $metadata): void
    {
        AuditLog::create([
            'user_id' => null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => ['command' => self::AUDIT_COMMAND] + $metadata,
        ]);
    }

    public static function key(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::upper(Str::ascii($value))));
    }
}
