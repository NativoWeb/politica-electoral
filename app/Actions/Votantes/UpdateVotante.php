<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Services\Import\TextNormalizer;
use App\Support\Cedula;
use App\Support\GeoTerritory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateVotante
{
    use ScopesTerritory;

    public const AMBIGUOUS_RENAME_WARNING = 'Se renombró solo el votante: hay varias personas electorales con el nombre anterior en el municipio y no se pudo saber cuál es la vinculada.';

    // Set by handle() when a rename could not be propagated to the linked electoral person.
    public ?string $warning = null;

    // Set by handle(): the changed fields with their previous and new values (for the audit's before/after state).
    public array $before = [];

    public array $after = [];

    // NOT NULL columns of lideres: an emptied form field arrives as null and must not reach the database.
    private const NOT_NULL_DEFAULTS = [
        'cargo' => '', 'votos' => 0, 'nivel_confianza' => 'sin_llamar', 'tipo' => 'directorio',
        'destacado' => false, 'militante' => false, 'autoriza_datos' => false, 'verificado' => false,
        'fallecido' => false, 'empresario' => false, 'reservista' => false, 'funcionario' => false,
        'exfuncionario' => false, 'gran_elector' => false,
    ];

    private const NOT_CLEARABLE = ['nombre', 'municipio'];

    public static function withoutNulls(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value !== null) {
                continue;
            }
            if (in_array($key, self::NOT_CLEARABLE, true)) {
                unset($data[$key]);
            } elseif (array_key_exists($key, self::NOT_NULL_DEFAULTS)) {
                $data[$key] = self::NOT_NULL_DEFAULTS[$key];
            }
        }

        return $data;
    }

    // $data must already be restricted to the fields the caller may change.
    public function handle(string $id, array $data): Lider
    {
        $this->warning = null;
        $this->before = $this->after = [];
        $data = Cedula::normalizeFields(self::withoutNulls($data));

        $lider = Lider::find($id);
        if (! $lider) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $this->authorizeLiderRow($lider, true);

        $cedula = $data['cedula'] ?? null;
        // The form resends the cédula on every save; an unchanged one (even if stored with dots) is left as is.
        if ($cedula !== null && $cedula === Cedula::normalize($lider->cedula)) {
            unset($data['cedula']);
        } elseif ($cedula !== null && $cedula !== '') {
            $existente = DB::table('lideres')->where('campaign_id', $lider->campaign_id)
                ->where('cedula', $cedula)->where('id', '!=', $id)->first();
            if ($existente) {
                throw new BusinessRuleException($this->duplicateCedulaMessage($cedula, $existente));
            }
        }

        $nuevoNombre = isset($data['nombre']) && is_string($data['nombre']) ? self::normalizeName($data['nombre']) : null;
        if ($nuevoNombre !== null) {
            $data['nombre'] = $nuevoNombre;
            if ($lider->nombre !== null && $nuevoNombre === self::normalizeName($lider->nombre)) {
                $nuevoNombre = null;
            }
        }

        try {
            DB::transaction(function () use ($lider, $data, $nuevoNombre) {
                $personaARenombrar = null;
                // Electoral persons are shared: only the platform renames them.
                if ($nuevoNombre !== null && $this->campaign()->canEditElectoral()) {
                    $candidatos = $this->linkedPersonIds($lider);
                    if ($candidatos->count() === 1) {
                        $personaARenombrar = $candidatos->first();
                    } elseif ($candidatos->count() > 1) {
                        $this->warning = self::AMBIGUOUS_RENAME_WARNING;
                    }
                }

                $original = $lider->getOriginal();
                $lider->update($data);
                $changed = array_keys(array_diff_key($lider->getChanges(), ['updated_at' => true]));
                $this->before = array_intersect_key($original, array_flip($changed));
                $this->after = $lider->only($changed);

                if ($personaARenombrar) {
                    self::renamePerson($personaARenombrar, $nuevoNombre);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException("Ya existe un líder con cédula {$cedula}.");
        }

        return $lider;
    }

    public static function normalizeName(string $name): string
    {
        return preg_replace('/\s+/u', ' ', trim($name));
    }

    // Electoral persons linked to a lider by its CURRENT name (case-insensitive) with a candidacy held where the lider
    // is: its municipio, or a contest above it (Cámara, Asamblea, Gobernación) or national. Only while this lider is
    // the campaign's one ficha with that name in the contest's territory: with a namesake, which one it is is unknown.
    public function linkedPersonIds(object $lider): Collection
    {
        if (! $lider->geographic_unit_id || ! $lider->nombre) {
            return collect();
        }

        $matches = DB::table('persons as p')
            ->join('candidacies as c', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->whereRaw('LOWER(TRIM(p.full_name)) = LOWER(TRIM(?))', [$lider->nombre])
            ->distinct()
            ->get(['p.id', 'con.geographic_unit_id as contest_geo_id']);

        return $matches
            ->filter(fn ($m) => GeoTerritory::covers($m->contest_geo_id, $lider->geographic_unit_id)
                && $this->onlyNamesakeIn($lider, $m->contest_geo_id))
            ->pluck('id')
            ->unique()
            ->values();
    }

    private function onlyNamesakeIn(object $lider, ?string $contestGeoId): bool
    {
        return DB::table('lideres')
            ->where(fn ($q) => ($lider->campaign_id ?? null) === null
                ? $q->whereNull('campaign_id')
                : $q->where('campaign_id', $lider->campaign_id))
            ->whereRaw('LOWER(TRIM(nombre)) = LOWER(TRIM(?))', [$lider->nombre])
            ->when($contestGeoId !== null, fn ($q) => $q->whereIn('geographic_unit_id', GeoTerritory::selfAndDescendants($contestGeoId)))
            ->count() === 1;
    }

    public static function renamePerson(string $personId, string $fullName): void
    {
        DB::table('persons')->where('id', $personId)->update([
            ...TextNormalizer::splitName($fullName),
            'full_name' => $fullName,
            'normalized_name' => TextNormalizer::normalizeName($fullName),
            'updated_at' => now(),
        ]);
    }
}
