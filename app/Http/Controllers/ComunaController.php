<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\AuditLog;
use App\Models\Lider;
use App\Services\Geo\BarrioMatcher;
use App\Services\Geo\ComunaAssigner;
use App\Services\Geo\ComunaCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Barrio → comuna (docs/geografia.md): the queue of barrio texts of the grupo político's records that got no comuna,
// the aliases that settle them (reassigning every record with that text) and the comuna of one ficha chosen by hand.
// Only the user's own grupo político and territory: records of a subgrupo are not touched from here.
class ComunaController extends Controller
{
    use ScopesTerritory;

    private const LIMIT = 500;

    public function __construct(
        private readonly ComunaCatalog $catalog,
        private readonly BarrioMatcher $matcher,
        private readonly ComunaAssigner $assigner,
    ) {}

    // GET /admin/territorio/barrios-sin-comuna?municipio_id=
    public function pendientes(Request $request): JsonResponse
    {
        $data = $request->validate(['municipio_id' => 'nullable|uuid']);
        if (! empty($data['municipio_id'])) {
            $this->authorizeGeo($data['municipio_id']);
        }

        $municipios = $this->municipiosConComunas();
        if ($municipios === []) {
            return response()->json(['success' => true, 'municipio' => null, 'municipios' => [], 'comunas' => [], 'barrios' => [], 'pendientes' => []]);
        }

        $municipioId = $data['municipio_id'] ?? $municipios[0]['id'];
        $municipio = collect($municipios)->firstWhere('id', $municipioId);
        abort_unless($municipio, 404, 'Ese municipio no tiene comunas.');

        $groups = [];
        foreach (ComunaAssigner::TABLES as $table => $municipioSql) {
            $rows = $this->ownRows($table, $municipioId)
                ->whereNull('comuna_id')
                ->where(fn ($q) => $q->whereNull('comuna_source')->orWhere('comuna_source', '!=', 'manual'))
                ->whereNotNull('barrio')->where('barrio', '!=', '')
                ->groupBy('barrio')
                ->orderByDesc(DB::raw('count(*)'))->orderBy('barrio')
                ->get(['barrio', DB::raw('count(*) AS n')]);

            foreach ($rows as $row) {
                $norm = $this->matcher->normalizeFor($municipioId, $row->barrio);
                if ($norm === '') {
                    continue;
                }
                $groups[$norm] ??= ['texto' => $norm, 'ejemplos' => [], 'lideres' => 0, 'event_attendees' => 0, 'organizaciones' => 0, 'total' => 0];
                $groups[$norm][$table] += (int) $row->n;
                $groups[$norm]['total'] += (int) $row->n;
                if (count($groups[$norm]['ejemplos']) < 3 && ! in_array(trim($row->barrio), $groups[$norm]['ejemplos'], true)) {
                    $groups[$norm]['ejemplos'][] = trim($row->barrio);
                }
            }
        }

        $campaignId = $this->campaign()->ownId();
        $pendientes = collect($groups)->sortByDesc('total')->take(self::LIMIT)->map(function ($g) use ($municipioId, $campaignId) {
            $match = $this->matcher->match($municipioId, $campaignId, $g['ejemplos'][0]);

            return $g + [
                'estado' => $match['status'],
                'sugerencia' => $match['status'] === BarrioMatcher::ASIGNADO ? [
                    'barrio_id' => $match['barrio_id'], 'barrio' => $match['barrio'], 'comuna_id' => $match['comuna_id'],
                    'comuna' => $this->catalog->nameOf($match['comuna_id'], $municipioId),
                ] : null,
                'candidatos' => array_slice($match['candidatos'], 0, 5),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'municipio' => $municipio,
            'municipios' => $municipios,
            'comunas' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo']], $this->catalog->comunasOf($municipioId)),
            'barrios' => DB::table('barrios')->where('municipio_id', $municipioId)->orderBy('nombre')
                ->get(['id', 'nombre', 'comuna_unit_id as comuna_id'])->all(),
            'pendientes' => $pendientes,
            'total_textos' => count($groups),
        ]);
    }

    // POST /admin/territorio/barrios/alias {municipio_id, texto, barrio_id?|comuna_id?}
    public function storeAlias(Request $request): JsonResponse
    {
        $data = $request->validate([
            'municipio_id' => 'required|uuid',
            'texto' => 'required|string|max:200',
            'barrio_id' => 'nullable|uuid|required_without:comuna_id',
            'comuna_id' => 'nullable|uuid|required_without:barrio_id',
        ]);

        $municipioId = $data['municipio_id'];
        $this->authorizeGeo($municipioId);
        abort_unless($this->catalog->hasComunas($municipioId), 404, 'Ese municipio no tiene comunas.');

        $norm = $this->matcher->normalizeFor($municipioId, $data['texto']);
        if ($norm === '') {
            return $this->invalid('texto', 'El texto no tiene un nombre de barrio.');
        }

        $barrio = null;
        if (! empty($data['barrio_id'])) {
            $barrio = DB::table('barrios')->where('id', $data['barrio_id'])->where('municipio_id', $municipioId)->first(['id', 'nombre', 'comuna_unit_id']);
            if (! $barrio) {
                return $this->invalid('barrio_id', 'Ese barrio no es de este municipio.');
            }
            if (! empty($data['comuna_id']) && $data['comuna_id'] !== $barrio->comuna_unit_id) {
                return $this->invalid('comuna_id', 'El barrio elegido es de otra comuna.');
            }
        }
        $comunaId = $barrio->comuna_unit_id ?? $data['comuna_id'];
        if (! $this->catalog->belongsTo($comunaId, $municipioId)) {
            return $this->invalid('comuna_id', 'Esa comuna no es de este municipio.');
        }

        $campaignId = $this->campaign()->ownId();
        [$aliasId, $counts] = DB::transaction(function () use ($campaignId, $municipioId, $norm, $barrio, $comunaId, $request) {
            $existing = DB::table('barrio_aliases')->where('campaign_id', $campaignId)->where('municipio_id', $municipioId)
                ->where('texto_normalizado', $norm)->lockForUpdate()->value('id');
            $aliasId = $existing ?? (string) Str::uuid();
            $values = ['barrio_id' => $barrio?->id, 'comuna_unit_id' => $comunaId, 'created_by' => $request->user()->id, 'updated_at' => now()];
            if ($existing) {
                DB::table('barrio_aliases')->where('id', $aliasId)->update($values);
            } else {
                DB::table('barrio_aliases')->insert($values + [
                    'id' => $aliasId, 'campaign_id' => $campaignId, 'municipio_id' => $municipioId,
                    'texto_normalizado' => $norm, 'created_at' => now(),
                ]);
            }

            $counts = [];
            foreach (array_keys(ComunaAssigner::TABLES) as $table) {
                $ids = $this->ownRows($table, $municipioId, false)
                    ->where(fn ($q) => $q->whereNull('comuna_source')->orWhere('comuna_source', '!=', 'manual'))
                    ->whereNotNull('barrio')
                    ->tap(fn ($q) => $this->containsWord($q, $norm))
                    ->get(['id', 'barrio'])
                    ->filter(fn ($r) => $this->matcher->normalizeFor($municipioId, $r->barrio) === $norm)
                    ->pluck('id')->all();
                foreach (array_chunk($ids, 500) as $chunk) {
                    DB::table($table)->whereIn('id', $chunk)->update(['comuna_id' => $comunaId, 'comuna_source' => 'auto']);
                }
                $counts[$table] = count($ids);
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'geo.barrio_alias',
                'entity_type' => 'barrio_alias',
                'entity_id' => $aliasId,
                'metadata' => ['municipio_id' => $municipioId, 'comuna_id' => $comunaId, 'barrio_id' => $barrio?->id,
                    'reemplaza' => (bool) $existing, 'reasignadas' => $counts],
                'ip_address' => $request->ip(),
            ]);

            return [$aliasId, $counts];
        });
        $this->matcher->forget();

        $total = array_sum($counts);

        return response()->json([
            'success' => true,
            'message' => "Asignado a {$this->catalog->nameOf($comunaId, $municipioId)}: {$total} ".($total === 1 ? 'registro' : 'registros').'.',
            'alias_id' => $aliasId,
            'texto' => $norm,
            'reasignadas' => $counts,
        ]);
    }

    // PATCH /votantes/{id}/comuna {comuna_id: uuid|null} → manual; {automatica: true} → back to the barrio's.
    public function updateFicha(Request $request, string $id): JsonResponse
    {
        abort_unless(Str::isUuid($id), 404);
        $data = $request->validate(['comuna_id' => 'nullable|uuid', 'automatica' => 'sometimes|boolean']);

        $lider = Lider::find($id);
        if (! $lider) {
            return response()->json(['success' => false, 'message' => 'No encontrado.'], 404);
        }
        $this->authorizeLiderRow($lider, true);

        $municipioId = $lider->geographic_unit_id;
        if (! $this->catalog->hasComunas($municipioId)) {
            return $this->invalid('comuna_id', 'El municipio de esta ficha no tiene comunas.');
        }

        if ($request->boolean('automatica')) {
            $values = $this->assigner->changesFor($municipioId, $lider->campaign_id, $lider->barrio, null, null)
                ?? ['comuna_id' => null, 'comuna_source' => null];
        } else {
            $comunaId = $data['comuna_id'] ?? null;
            if ($comunaId !== null && ! $this->catalog->belongsTo($comunaId, $municipioId)) {
                return $this->invalid('comuna_id', 'Esa comuna no es del municipio de la ficha.');
            }
            $values = ['comuna_id' => $comunaId, 'comuna_source' => 'manual'];
        }

        DB::table('lideres')->where('id', $lider->id)->update($values + ['updated_at' => now()]);
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'geo.ficha_comuna',
            'entity_type' => 'lider',
            'entity_id' => $lider->id,
            'before_state' => ['comuna_id' => $lider->comuna_id, 'comuna_source' => $lider->comuna_source],
            'after_state' => $values,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $values['comuna_id'] ? 'Comuna actualizada.' : 'Comuna quitada.',
        ] + $this->catalog->fichaPayload($municipioId, $values['comuna_id'], $values['comuna_source']));
    }

    // [{id, nombre}] of the municipios with comunas inside the user's territory.
    private function municipiosConComunas(): array
    {
        $query = DB::table('geographic_units as m')
            ->where('m.type', 'municipality')
            ->whereExists(fn ($q) => $q->from('geographic_units as c')->whereColumn('c.parent_id', 'm.id')->whereIn('c.type', ComunaCatalog::TYPES))
            ->orderBy('m.canonical_name');

        return $this->scopeToTerritory($query, 'm.id')->get(['m.id', 'm.canonical_name as nombre'])
            ->map(fn ($m) => ['id' => $m->id, 'nombre' => $m->nombre])->all();
    }

    // Rows of the user's own grupo político in the municipio (an attendee is where its event is). The queue leaves out
    // the inactive, deleted and discarded ones; an alias reassigns them all.
    private function ownRows(string $table, string $municipioId, bool $activeOnly = true)
    {
        $query = DB::table($table)->whereRaw(ComunaAssigner::TABLES[$table].' = ?', [$municipioId]);
        $own = $this->campaign()->ownId();
        if ($own !== null) {
            $query->where("{$table}.campaign_id", $own);
        }

        if (! $activeOnly) {
            return $query;
        }

        return match ($table) {
            'lideres' => $query->where('is_active', true),
            'organizaciones' => $query->whereNull('deleted_at'),
            'event_attendees' => $query->where('link_status', '!=', 'descartado'),
        };
    }

    // SQL prefilter before the exact comparison in PHP: the barrio must contain the longest word of the normalized
    // text (unaccented in PostgreSQL; numbers and Roman numerals are skipped because both spellings exist).
    private function containsWord($query, string $norm): void
    {
        $words = array_filter(explode(' ', $norm), fn ($w) => strlen($w) >= 3 && ctype_alpha($w) && ! preg_match('/^[IVX]+$/', $w));
        if ($words === []) {
            return;
        }
        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a));
        $like = '%'.$words[0].'%';
        if (DB::connection()->getDriverName() === 'pgsql') {
            $query->whereRaw('upper(unaccent(barrio)) LIKE ?', [$like]);
        } else {
            $query->whereRaw('upper(barrio) LIKE ?', [$like]);
        }
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'errors' => [$field => [$message]]], 422);
    }
}
