<?php

namespace App\Services\Empresarios;

use App\Http\Controllers\MapaPoliticoController;
use App\Models\Organizacion;
use App\Models\OrganizacionMiembro;
use App\Services\CachedQueries;
use App\Support\CurrentCampaign;
use App\Support\Nit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The filtered list of Empresarios y gremios, shared by the screen and the Excel/PDF export (docs/empresarios.md).
class OrganizacionListing
{
    public const FILTERS = ['provincia', 'municipio', 'tipo', 'sector_economico', 'sector', 'gremio', 'buscar', 'estado', 'campana'];

    public function __construct(private OrganizacionScope $scope, private CurrentCampaign $campaign) {}

    public function filters(Request $request): array
    {
        $uuid = fn ($v) => is_string($v) && Str::isUuid($v) ? $v : null;
        $text = fn ($v) => is_string($v) && trim($v) !== '' ? Str::limit(trim($v), 100, '') : null;

        return [
            'provincia' => $text($request->query('provincia')),
            'municipio' => $uuid($request->query('municipio')),
            'tipo' => array_key_exists((string) $request->query('tipo'), Organizacion::TIPOS) ? $request->query('tipo') : null,
            'sector_economico' => $uuid($request->query('sector_economico')),
            'sector' => $uuid($request->query('sector')),
            'gremio' => $uuid($request->query('gremio')),
            'buscar' => $text($request->query('buscar')),
            'estado' => in_array($request->query('estado'), ['inactivos', 'todos'], true) ? $request->query('estado') : null,
            'campana' => $this->campaign->filterId($uuid($request->query('campana'))),
        ];
    }

    // [{id, tipo, nombre, nit, municipio, provincia, sector_economico, sector, representante, celular, miembros, ...}]
    public function rows(array $f, ?int $limit = null): array
    {
        $query = $this->scope->query('o')
            ->leftJoin('geographic_units as m', 'm.id', '=', 'o.municipio_id')
            ->leftJoin('geographic_units as p', 'p.id', '=', 'm.parent_id')
            ->leftJoin('org_catalogos as se', 'se.id', '=', 'o.sector_economico_id')
            ->leftJoin('org_catalogos as s', 's.id', '=', 'o.sector_id')
            ->select(['o.id', 'o.campaign_id', 'o.tipo', 'o.nombre', 'o.nit', 'o.nit_dv', 'o.representante_nombre', 'o.representante_cedula',
                'o.representante_celular', 'o.representante_lider_id', 'o.telefono', 'o.correo', 'o.sitio_web', 'o.departamento', 'o.direccion', 'o.barrio',
                'o.empleados', 'o.empleados_rango', 'o.observaciones', 'o.is_active', 'o.municipio_id', 'o.sector_economico_id', 'o.sector_id',
                'm.canonical_name as municipio', 'p.canonical_name as provincia', 'se.nombre as sector_economico', 's.nombre as sector'])
            ->selectSub(DB::table('organizacion_miembros as om')->whereColumn('om.organizacion_id', 'o.id')->selectRaw('count(distinct om.lider_id)'), 'miembros')
            ->selectSub(DB::table('organizacion_gremios as og')->join('organizaciones as e', 'e.id', '=', 'og.empresa_id')
                ->whereNull('e.deleted_at')->whereColumn('og.gremio_id', 'o.id')->selectRaw('count(*)'), 'empresas')
            ->when($f['estado'] === null, fn ($q) => $q->where('o.is_active', true))
            ->when($f['estado'] === 'inactivos', fn ($q) => $q->where('o.is_active', false))
            ->when($f['tipo'], fn ($q, $v) => $q->where('o.tipo', $v))
            ->when($f['municipio'], fn ($q, $v) => $q->where('o.municipio_id', $v))
            ->when(! $f['municipio'] && $f['provincia'], fn ($q) => $q->whereIn('o.municipio_id', $this->provinciaIds($f['provincia'])))
            ->when($f['sector_economico'], fn ($q, $v) => $q->where('o.sector_economico_id', $v))
            ->when($f['sector'], fn ($q, $v) => $q->where('o.sector_id', $v))
            ->when($f['campana'], fn ($q, $v) => $q->where('o.campaign_id', $v))
            ->when($f['gremio'], fn ($q, $v) => $q->where(fn ($w) => $w->where('o.id', $v)->orWhereExists(fn ($e) => $e->select(DB::raw(1))
                ->from('organizacion_gremios as fg')->whereColumn('fg.empresa_id', 'o.id')->where('fg.gremio_id', $v))))
            ->when($f['buscar'], fn ($q, $v) => $q->where(function ($w) use ($v) {
                self::whereContains($w, 'o.nombre', $v);
                self::whereContains($w, 'o.representante_nombre', $v, 'or');
                $digits = preg_replace('/\D/', '', $v);
                if (strlen($digits) >= 3) {
                    $w->orWhere('o.nit', 'like', '%'.$digits.'%');
                }
            }))
            ->orderBy('o.nombre')->orderBy('o.id');

        $items = ($limit ? $query->limit($limit) : $query)->get();
        $gremios = $this->gremiosOf($items->where('tipo', 'empresa')->pluck('id')->all());

        return $items->map(fn ($o) => [
            'id' => $o->id,
            'campaign_id' => $o->campaign_id,
            'tipo' => $o->tipo,
            'tipo_label' => Organizacion::TIPOS[$o->tipo] ?? $o->tipo,
            'nombre' => $o->nombre,
            'nit' => Nit::format($o->nit, $o->nit_dv),
            'municipio' => $o->municipio,
            'municipio_id' => $o->municipio_id,
            'provincia' => $o->provincia,
            'departamento' => $o->departamento,
            'sector_economico' => $o->sector_economico,
            'sector' => $o->sector,
            'representante' => $o->representante_nombre,
            'representante_cedula' => $o->representante_cedula,
            'representante_celular' => $o->representante_celular,
            'telefono' => $o->telefono,
            'celular' => $o->representante_celular ?: $o->telefono,
            'correo' => $o->correo,
            'sitio_web' => $o->sitio_web,
            'direccion' => $o->direccion,
            'barrio' => $o->barrio,
            'empleados' => $o->empleados ?? $o->empleados_rango,
            'observaciones' => $o->observaciones,
            'is_active' => (bool) $o->is_active,
            'miembros' => (int) $o->miembros,
            'empresas' => (int) $o->empresas,
            'gremios' => $gremios[$o->id] ?? [],
        ])->all();
    }

    // [empresa id => [gremio name, ...]] (only gremios not deleted).
    private function gremiosOf(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('organizacion_gremios as og')->join('organizaciones as g', 'g.id', '=', 'og.gremio_id')->whereNull('g.deleted_at')
                ->whereIn('og.empresa_id', $chunk)->orderBy('g.nombre')->get(['og.empresa_id', 'g.nombre']) as $r) {
                $out[$r->empresa_id][] = $r->nombre;
            }
        }

        return $out;
    }

    // Municipios the user can see: [{id, name, provincia}].
    public function municipios(): array
    {
        $all = CachedQueries::allMunicipios();
        $allowed = request()->user()?->allowedGeoIds();

        return $allowed === null ? $all : array_values(array_filter($all, fn ($m) => in_array($m['id'], $allowed, true)));
    }

    public function provincias(): array
    {
        $names = collect($this->municipios())->pluck('provincia')->filter()->unique()->sort()->values()->all();
        $metro = collect($this->municipios())->contains(fn ($m) => in_array($m['name'], MapaPoliticoController::AREA_METROPOLITANA_MUNICIPIOS, true));

        return $metro && ! in_array(MapaPoliticoController::AREA_METROPOLITANA, $names, true)
            ? [MapaPoliticoController::AREA_METROPOLITANA, ...$names] : $names;
    }

    private function provinciaIds(string $provincia): array
    {
        return collect($this->municipios())
            ->filter(fn ($m) => $provincia === MapaPoliticoController::AREA_METROPOLITANA
                ? in_array($m['name'], MapaPoliticoController::AREA_METROPOLITANA_MUNICIPIOS, true)
                : ($m['provincia'] ?? '') === $provincia)
            ->pluck('id')->values()->all() ?: ['00000000-0000-0000-0000-000000000000'];
    }

    // Roles for the screen: [{value, label}].
    public static function roles(): array
    {
        return collect(OrganizacionMiembro::ROLES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all();
    }

    public static function whereContains($query, string $column, string $value, string $boolean = 'and')
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower($value)).'%';
        if (DB::connection()->getDriverName() === 'pgsql') {
            return $query->whereRaw("unaccent(LOWER(COALESCE({$column}, ''))) LIKE unaccent(?)", [$like], $boolean);
        }

        return $query->whereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$like], $boolean);
    }
}
