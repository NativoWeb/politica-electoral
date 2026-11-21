<?php

namespace App\Services\Eventos\Actas;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\EventActa;
use App\Models\EventCompromiso;
use App\Services\Empresarios\OrganizacionScope;
use App\Services\Eventos\EventScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The compromisos of CONFIRMED actas the current user may see (request()->user()): events' with view.eventos,
// organizations' with view.empresarios, visible campaigns and territory. Shared by the web follow-up screen
// (EventoCompromisoController) and the WhatsApp agent (AgentActaController).
class CompromisoListing
{
    use ScopesTerritory;

    public const FILTER_KEYS = ['estado', 'vencidos', 'responsable', 'municipio', 'evento', 'organizacion', 'origen', 'desde', 'hasta', 'q'];

    public function __construct(private EventScope $events, private OrganizacionScope $orgs) {}

    public function query(array $f)
    {
        $today = EventCompromiso::today();
        $events = $this->seesEvents() && $f['origen'] !== 'organizacion';
        $orgs = $this->seesOrgs() && $f['origen'] !== 'evento';
        $query = DB::table('event_compromisos as c')
            ->join('event_actas as a', 'a.id', '=', 'c.acta_id')
            ->where('a.estado', EventActa::CONFIRMADA)
            ->leftJoin('events as ev', 'ev.id', '=', 'c.event_id')
            ->leftJoin('organizaciones as o', 'o.id', '=', 'c.organizacion_id')
            ->leftJoin('geographic_units as m', fn ($j) => $j->on('m.id', '=', 'ev.municipio_id')->orOn('m.id', '=', 'o.municipio_id'))
            ->where(function ($w) use ($events, $orgs) {
                if ($events) {
                    $w->orWhere(fn ($e) => $this->events->scopeEvents($e->whereNotNull('c.event_id')));
                }
                if ($orgs) {
                    $w->orWhere(fn ($o) => $this->orgs->scope($o->whereNotNull('c.organizacion_id'), 'o'));
                }
                if (! $events && ! $orgs) {
                    $w->whereRaw('1 = 0');
                }
            })
            ->select(['c.id', 'c.descripcion', 'c.estado', 'c.fecha_limite', 'c.campaign_id', 'c.created_at', 'a.id as acta_id', 'a.titulo as acta_titulo',
                'a.fecha as acta_fecha', 'ev.id as event_id', 'ev.nombre as evento', 'o.id as organizacion_id', 'o.nombre as organizacion',
                'm.id as municipio_id', 'm.canonical_name as municipio'])
            ->selectSub(DB::table('event_compromiso_avances as av')->whereColumn('av.compromiso_id', 'c.id')->selectRaw('count(*)'), 'avances')
            ->when($f['estado'], fn ($q, $v) => $q->where('c.estado', $v))
            ->when($f['vencidos'], fn ($q) => $q->whereNotNull('c.fecha_limite')->where('c.fecha_limite', '<', $today)->whereIn('c.estado', EventCompromiso::ABIERTOS))
            ->when($f['municipio'], fn ($q, $v) => $q->where('m.id', $v))
            ->when($f['evento'], fn ($q, $v) => $q->where('ev.id', $v))
            ->when($f['organizacion'], fn ($q, $v) => $q->where('o.id', $v))
            ->when($f['desde'], fn ($q, $v) => $q->where('a.fecha', '>=', $v))
            ->when($f['hasta'], fn ($q, $v) => $q->where('a.fecha', '<', Carbon::parse($v)->addDay()->toDateString()))
            ->when($f['responsable'], fn ($q, $v) => $q->whereExists(fn ($r) => $this->whereContains(
                $r->select(DB::raw(1))->from('event_compromiso_responsables as r')->whereColumn('r.compromiso_id', 'c.id'), 'r.nombre', $v)))
            ->when($f['q'], fn ($q, $v) => $this->whereContains($q, 'c.descripcion', $v))
            ->when($f['lider'] ?? null, fn ($q, $v) => $q->whereExists(fn ($r) => $r->select(DB::raw(1))
                ->from('event_compromiso_responsables as r')->whereColumn('r.compromiso_id', 'c.id')->where('r.lider_id', $v)));

        // Open and overdue first, then by deadline (no deadline last) and newest acta.
        return $query
            ->orderByRaw("CASE WHEN c.estado IN ('pendiente', 'en_curso') THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN c.fecha_limite IS NULL THEN 1 ELSE 0 END')
            ->orderBy('c.fecha_limite')
            ->orderByDesc('a.fecha')->orderBy('c.id');
    }

    // [compromiso id => [{nombre, telefono, lider_id}]]
    public function responsables(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('event_compromiso_responsables')->whereIn('compromiso_id', $ids)->orderBy('orden')
            ->get(['compromiso_id', 'nombre', 'telefono', 'lider_id'])
            ->groupBy('compromiso_id')
            ->map(fn ($g) => $g->map(fn ($r) => ['nombre' => $r->nombre, 'telefono' => $r->telefono, 'lider_id' => $r->lider_id])->values()->all())
            ->all();
    }

    public function row(object $c, array $responsables): array
    {
        return [
            'id' => $c->id,
            'descripcion' => $c->descripcion,
            'estado' => $c->estado,
            'fecha_limite' => $c->fecha_limite ? substr((string) $c->fecha_limite, 0, 10) : null,
            'vencido' => self::isVencido($c),
            'avances' => (int) $c->avances,
            'responsables' => $responsables,
            'acta' => ['id' => $c->acta_id, 'titulo' => $c->acta_titulo, 'fecha' => $c->acta_fecha ? substr((string) $c->acta_fecha, 0, 10) : null],
            'origen' => $c->event_id ? 'evento' : 'organizacion',
            'evento' => $c->event_id ? ['id' => $c->event_id, 'nombre' => $c->evento, 'municipio' => $c->municipio] : null,
            'organizacion' => $c->organizacion_id ? ['id' => $c->organizacion_id, 'nombre' => $c->organizacion, 'municipio' => $c->municipio] : null,
            'campaign_id' => $c->campaign_id,
            'editable' => $this->canManage($c->event_id ? 'evento' : 'organizacion') && $this->campaign()->isOwn($c->campaign_id),
        ];
    }

    // Each phone with its responsable, only those who have one: "Ana Pérez: 3001234567; Beto: 3109990000" (null if none).
    // A list of phones alone could not say whose each one is.
    public static function telefonosConNombre(array $responsables): ?string
    {
        $out = [];
        foreach ($responsables as $r) {
            $telefono = trim((string) ($r['telefono'] ?? ''));
            if ($telefono !== '') {
                $out[] = trim((string) ($r['nombre'] ?? '')).': '.$telefono;
            }
        }

        return $out ? implode('; ', $out) : null;
    }

    public static function isVencido(object $c): bool
    {
        return $c->fecha_limite !== null && in_array($c->estado, EventCompromiso::ABIERTOS, true)
            && substr((string) $c->fecha_limite, 0, 10) < EventCompromiso::today();
    }

    public function canManage(string $origen = 'evento'): bool
    {
        return (bool) request()->user()?->hasPermission($origen === 'evento' ? 'manage.eventos' : 'manage.empresarios');
    }

    public function seesEvents(): bool
    {
        return EventScope::canSeeAttendees(request()->user());
    }

    public function seesOrgs(): bool
    {
        return OrganizacionScope::canView(request()->user());
    }

    public function whereContains($query, string $column, string $value)
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($value)).'%';
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->whereRaw("unaccent(LOWER({$column})) LIKE unaccent(?)", [$like]);
        }

        return $query->whereRaw("LOWER({$column}) LIKE ?", [$like]);
    }
}
