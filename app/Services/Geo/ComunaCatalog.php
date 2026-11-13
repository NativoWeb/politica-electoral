<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\DB;

// The comunas/corregimientos and barrios of a municipio, read once per request (scoped in the container).
class ComunaCatalog
{
    public const TYPES = ['comuna', 'corregimiento'];

    // Other spellings of a municipio that people add to a barrio ("SAN ALONSO, B/MANGA"), by DANE code.
    private const MUNICIPIO_ABBREVIATIONS = [
        '68001' => ['B MANGA', 'BMANGA', 'BGA', 'BUCARAMANGA SANTANDER'],
    ];

    private array $comunas = [];

    private array $barrioNames = [];

    private array $strips = [];

    private ?array $municipios = null;

    private array $others = [];

    private array $eventMunicipios = [];

    // [{id, nombre, tipo, codigo}] comunas first, then corregimientos, by code. Most municipios have none: that is
    // answered with parent_id and type alone.
    public function comunasOf(?string $municipioId): array
    {
        if (! $municipioId) {
            return [];
        }
        if (isset($this->comunas[$municipioId])) {
            return $this->comunas[$municipioId];
        }
        if (! DB::table('geographic_units')->where('parent_id', $municipioId)->whereIn('type', self::TYPES)->exists()) {
            return $this->comunas[$municipioId] = [];
        }

        return $this->comunas[$municipioId] = DB::table('geographic_units')
            ->where('parent_id', $municipioId)
            ->whereIn('type', self::TYPES)
            ->orderByRaw("CASE WHEN type = 'comuna' THEN 0 ELSE 1 END")
            ->orderBy('official_code')
            ->get(['id', 'canonical_name', 'type', 'official_code'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'nombre' => $c->canonical_name,
                'tipo' => $c->type,
                'codigo' => $c->official_code,
            ])->all();
    }

    // What a ficha shows: its comuna and the options of its municipio (empty when the municipio has no comunas, and
    // then the selector is hidden).
    public function fichaPayload(?string $municipioId, ?string $comunaId, ?string $source): array
    {
        return [
            'comuna_id' => $comunaId,
            'comuna_source' => $source,
            'comuna_nombre' => $this->nameOf($comunaId, $municipioId),
            'comunas' => array_map(fn ($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'tipo' => $c['tipo']], $this->comunasOf($municipioId)),
        ];
    }

    // An attendee is where its event is; asked once per event (a list import saves thousands of attendees).
    public function municipioOfEvent(?string $eventId): ?string
    {
        if (! $eventId) {
            return null;
        }

        return $this->eventMunicipios[$eventId] ??= DB::table('events')->where('id', $eventId)->value('municipio_id') ?? '';
    }

    public function hasComunas(?string $municipioId): bool
    {
        return $this->comunasOf($municipioId) !== [];
    }

    public function belongsTo(?string $comunaId, ?string $municipioId): bool
    {
        return $comunaId !== null && in_array($comunaId, array_column($this->comunasOf($municipioId), 'id'), true);
    }

    public function nameOf(?string $comunaId, ?string $municipioId): ?string
    {
        foreach ($this->comunasOf($municipioId) as $comuna) {
            if ($comuna['id'] === $comunaId) {
                return $comuna['nombre'];
            }
        }

        return null;
    }

    // Normalized phrases of the municipio itself, removed from a barrio text before matching.
    public function ownMunicipioStrips(string $municipioId): array
    {
        if (isset($this->strips[$municipioId])) {
            return $this->strips[$municipioId];
        }
        $unit = DB::table('geographic_units')->where('id', $municipioId)->first(['canonical_name', 'official_code']);
        $phrases = $unit ? [BarrioNormalizer::normalize($unit->canonical_name), ...array_map(
            fn ($p) => BarrioNormalizer::normalize($p),
            self::MUNICIPIO_ABBREVIATIONS[$unit->official_code] ?? []
        )] : [];
        usort($phrases, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $this->strips[$municipioId] = array_values(array_unique(array_filter($phrases)));
    }

    // Normalized names of the barrios of the municipio.
    public function barrioNames(string $municipioId): array
    {
        return $this->barrioNames[$municipioId] ??= DB::table('barrios')->where('municipio_id', $municipioId)
            ->pluck('nombre_normalizado')->all();
    }

    // Normalized names of every other municipio, except those that are also part of one of this municipio's barrio
    // names ("SAN MIGUEL" is a barrio of Bucaramanga and a municipio of Santander).
    public function otherMunicipioNames(string $municipioId): array
    {
        if (isset($this->others[$municipioId])) {
            return $this->others[$municipioId];
        }
        $this->municipios ??= DB::table('geographic_units')->where('type', 'municipality')
            ->get(['id', 'canonical_name'])
            ->map(fn ($m) => ['id' => $m->id, 'norm' => BarrioNormalizer::normalize($m->canonical_name)])
            ->all();
        $barrios = $this->barrioNames($municipioId);
        $own = $this->ownMunicipioStrips($municipioId);

        return $this->others[$municipioId] = array_values(array_unique(array_filter(array_map(
            fn ($m) => $m['id'] !== $municipioId
                && strlen($m['norm']) >= 4
                && ! in_array($m['norm'], $own, true)
                && ! collect($barrios)->contains(fn ($b) => BarrioNormalizer::containsPhrase($b, $m['norm']))
                ? $m['norm'] : null,
            $this->municipios
        ))));
    }
}
