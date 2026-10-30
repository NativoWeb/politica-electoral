<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Services\Import\TextNormalizer;
use App\Support\Cedula;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateVotante
{
    use ScopesTerritory;

    public const AMBIGUOUS_RENAME_WARNING = 'Se renombró solo el votante: hay varias personas electorales con el nombre anterior en el municipio y no se pudo saber cuál es la vinculada.';

    // Set by handle() when a rename could not be propagated to the linked electoral person.
    public ?string $warning = null;

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

                $lider->update($data);

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

    // Electoral persons linked to a lider by its CURRENT name (case-insensitive) with a candidacy in the lider's municipio.
    public function linkedPersonIds(object $lider): Collection
    {
        if (! $lider->geographic_unit_id || ! $lider->nombre) {
            return collect();
        }

        return DB::table('persons as p')
            ->join('candidacies as c', 'c.person_id', '=', 'p.id')
            ->join('contests as con', 'c.contest_id', '=', 'con.id')
            ->whereRaw('LOWER(p.full_name) = LOWER(?)', [$lider->nombre])
            ->where('con.geographic_unit_id', $lider->geographic_unit_id)
            ->distinct()
            ->pluck('p.id');
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
