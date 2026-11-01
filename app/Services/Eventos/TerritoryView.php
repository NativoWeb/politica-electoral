<?php

namespace App\Services\Eventos;

use App\Actions\Eventos\ApproveAttendees;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Http\Requests\Eventos\AttendeeInput;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// "Vista por territorio" (/eventos/territorio): for one municipio and, optionally, one barrio, the lideres of the visible
// campaigns, the events held there and their attendees. The screen and its Excel use these same queries.
// A barrio matches when its key is the same: lowercase, without accents and with spaces collapsed (AttendeeInput::barrioKey,
// the key event_barrios and event_attendees already store), so "San Martín", "SAN  MARTIN" and "san martin" are one barrio.
class TerritoryView
{
    use ScopesTerritory;

    public function __construct(private EventScope $events) {}

    public static function key(?string $barrio): ?string
    {
        return AttendeeInput::barrioKey($barrio);
    }

    // Active lideres of the municipio (not the event attendees turned into fichas: they are listed as attendees).
    public function lideres(string $municipioId, ?string $key): Builder
    {
        $query = $this->visibleLideres('l')
            ->where('l.geographic_unit_id', $municipioId)
            ->where('l.is_active', true)
            ->where(fn ($q) => $q->where('l.tipo', '!=', ApproveAttendees::TIPO)->orWhereNull('l.tipo'));

        if ($key !== null) {
            $query->whereIn('l.barrio', $this->liderBarriosMatching($municipioId, $key));
        }

        return $query->select(['l.id', 'l.nombre', 'l.cedula', 'l.telefono', 'l.barrio', 'l.cargo', 'l.partido', 'l.campaign_id'])
            ->orderBy('l.nombre')->orderBy('l.id');
    }

    // Events of the municipio; with a barrio, the events whose barrios include it.
    public function eventos(string $municipioId, ?string $key): Builder
    {
        $query = $this->events->events()
            ->leftJoin('event_types as t', 't.id', '=', 'ev.event_type_id')
            ->where('ev.municipio_id', $municipioId)
            ->select(['ev.id', 'ev.nombre', 'ev.status', 'ev.starts_at', 'ev.campaign_id', 't.nombre as tipo'])
            ->selectSub(DB::table('event_attendees as c')->whereColumn('c.event_id', 'ev.id')->selectRaw('count(*)'), 'asistentes');

        if ($key !== null) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('event_barrios as eb')
                ->whereColumn('eb.event_id', 'ev.id')->where('eb.nombre_normalizado', $key))
                ->selectSub(DB::table('event_attendees as c')->whereColumn('c.event_id', 'ev.id')
                    ->where('c.barrio_normalizado', $key)->selectRaw('count(*)'), 'asistentes_barrio');
        }

        return $query->orderByDesc('ev.starts_at')->orderBy('ev.id');
    }

    // Attendees of the municipio's events; with a barrio, those who live in it.
    public function asistentes(string $municipioId, ?string $key): Builder
    {
        return $this->events->events()
            ->join('event_attendees as ea', 'ea.event_id', '=', 'ev.id')
            ->where('ev.municipio_id', $municipioId)
            ->when($key !== null, fn ($q) => $q->where('ea.barrio_normalizado', $key))
            ->select([
                'ea.id', 'ea.nombres', 'ea.apellidos', 'ea.cedula', 'ea.celular', 'ea.barrio', 'ea.source',
                'ea.link_status', 'ea.lider_id', 'ev.id as event_id', 'ev.nombre as evento', 'ev.starts_at', 'ev.campaign_id',
            ])
            ->orderByDesc('ev.starts_at')->orderBy('ea.apellidos')->orderBy('ea.nombres')->orderBy('ea.id');
    }

    public function kpis(string $municipioId, ?string $key): array
    {
        $asistentes = DB::query()->fromSub($this->asistentes($municipioId, $key)->reorder(), 'a');

        return [
            'lideres' => DB::query()->fromSub($this->lideres($municipioId, $key)->reorder(), 'l')->count(),
            'eventos' => DB::query()->fromSub($this->eventos($municipioId, $key)->reorder(), 'e')->count(),
            'asistentes' => (clone $asistentes)->count(),
            'personas' => (clone $asistentes)->whereNotNull('cedula')->distinct()->count('cedula'),
            'en_votantes' => (clone $asistentes)->whereNotNull('lider_id')->distinct()->count('lider_id'),
        ];
    }

    // Barrio suggestions of the municipio from lideres, the events' barrios and the attendees, one per key, shown with
    // the spelling used most often.
    public function barrios(string $municipioId): array
    {
        $spellings = $this->visibleLideres()
            ->where('geographic_unit_id', $municipioId)->where('is_active', true)
            ->whereNotNull('barrio')->where('barrio', '!=', '')
            ->groupBy('barrio')->selectRaw('barrio as nombre, count(*) as total')->get()
            ->concat($this->events->events()->join('event_barrios as eb', 'eb.event_id', '=', 'ev.id')
                ->where('ev.municipio_id', $municipioId)
                ->groupBy('eb.nombre')->selectRaw('eb.nombre as nombre, count(*) as total')->get())
            ->concat($this->events->events()->join('event_attendees as ea', 'ea.event_id', '=', 'ev.id')
                ->where('ev.municipio_id', $municipioId)->whereNotNull('ea.barrio')
                ->groupBy('ea.barrio')->selectRaw('ea.barrio as nombre, count(*) as total')->get());

        return $spellings
            ->map(fn ($s) => ['nombre' => trim(preg_replace('/\s+/u', ' ', $s->nombre)), 'total' => (int) $s->total, 'key' => self::key($s->nombre)])
            ->filter(fn ($s) => $s['key'] !== null)
            ->groupBy('key')
            ->map(function ($group, $key) {
                $best = $group->groupBy('nombre')->map(fn ($g) => $g->sum('total'))
                    ->sortKeys()->sortDesc()->keys()->first();

                return ['nombre' => $best, 'key' => $key, 'total' => $group->sum('total')];
            })
            ->sortBy('key')->values()->all();
    }

    // The stored spellings of lideres.barrio in the municipio that normalize to the key (lideres keep no normalized
    // column, and PostgreSQL's unaccent is not available in the SQLite tests).
    private function liderBarriosMatching(string $municipioId, string $key): array
    {
        return $this->visibleLideres()
            ->where('geographic_unit_id', $municipioId)
            ->whereNotNull('barrio')->distinct()->pluck('barrio')
            ->filter(fn ($b) => self::key($b) === $key)
            ->values()->all();
    }
}
