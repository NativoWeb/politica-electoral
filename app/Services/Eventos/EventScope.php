<?php

namespace App\Services\Eventos;

use App\Actions\Eventos\ApproveAttendees;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The events a user may open (visible campaigns: own + subcampaigns, the user's territory, not deleted), for the
// screens that show event data outside the module: the person's ficha, Votantes and the territory view.
class EventScope
{
    use ScopesTerritory;

    // Seeing who attended an event is what view.eventos (or manage.eventos) grants; register.asistentes does not.
    public static function canSeeAttendees(?User $user): bool
    {
        return (bool) $user && ($user->hasPermission('view.eventos') || $user->hasPermission('manage.eventos'));
    }

    // The lider tipo a user may see: without view.eventos an event attendee is an ordinary lider ('directorio').
    public static function visibleTipo(?string $tipo, ?User $user): ?string
    {
        return $tipo === ApproveAttendees::TIPO && ! self::canSeeAttendees($user) ? 'directorio' : $tipo;
    }

    public function events(string $alias = 'ev'): Builder
    {
        $query = DB::table("events as {$alias}")->whereNull("{$alias}.deleted_at");
        $this->campaign()->scope($query, "{$alias}.campaign_id");
        $this->scopeToTerritory($query, "{$alias}.municipio_id");

        return $query;
    }

    // A visible event id, or null for anything else (not a UUID, deleted, another campaign, outside the territory).
    public function visibleEventId($id): ?string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return $this->events()->where('ev.id', $id)->exists() ? $id : null;
    }

    // Attendance rows of visible events, for a whereExists on lideres.id.
    public function attendanceOf(Builder $query, string $liderColumn = 'lideres.id', ?string $eventId = null): Builder
    {
        $query->select(DB::raw(1))
            ->from('event_attendees as ea')
            ->join('events as ev', 'ev.id', '=', 'ea.event_id')
            ->whereColumn('ea.lider_id', $liderColumn)
            ->when($eventId, fn ($q, $v) => $q->where('ea.event_id', $v));

        return $this->scopeEvents($query);
    }

    // How many visible events a lider attended (a subquery for addSelect).
    public function attendedCount(string $liderColumn = 'lideres.id'): Builder
    {
        return $this->scopeEvents(DB::table('event_attendees as ea')
            ->join('events as ev', 'ev.id', '=', 'ea.event_id')
            ->whereColumn('ea.lider_id', $liderColumn)
            ->selectRaw('count(distinct ea.event_id)'));
    }

    // Visible events for a selector, newest first.
    public function options(int $limit = 300): array
    {
        return $this->events()
            ->leftJoin('geographic_units as m', 'm.id', '=', 'ev.municipio_id')
            ->orderByDesc('ev.starts_at')->limit($limit)
            ->get(['ev.id', 'ev.nombre', 'ev.starts_at', 'm.canonical_name as municipio'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'starts_at' => self::iso($e->starts_at),
                'municipio' => $e->municipio,
            ])->all();
    }

    public function scopeEvents(Builder $query, string $alias = 'ev'): Builder
    {
        $query->whereNull("{$alias}.deleted_at");
        $this->campaign()->scope($query, "{$alias}.campaign_id");
        $this->scopeToTerritory($query, "{$alias}.municipio_id");

        return $query;
    }

    public static function iso($date): ?string
    {
        return $date ? Carbon::parse($date, 'UTC')->setTimezone('America/Bogota')->toIso8601String() : null;
    }
}
