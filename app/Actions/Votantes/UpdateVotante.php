<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Services\Import\TextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UpdateVotante
{
    use ScopesTerritory;

    public const AMBIGUOUS_RENAME_WARNING = 'Se renombró solo el votante: hay varias personas electorales con el nombre anterior en el municipio y no se pudo saber cuál es la vinculada.';

    // Set by handle() when a rename could not be propagated to the linked electoral person.
    public ?string $warning = null;

    // $data must already be restricted to the fields the caller may change.
    public function handle(string $id, array $data): Lider
    {
        $this->warning = null;

        $lider = Lider::find($id);
        if (! $lider) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $this->authorizeGeo($lider->geographic_unit_id);

        $cedula = $data['cedula'] ?? null;
        if ($cedula !== null && $cedula !== '' && $cedula !== $lider->cedula) {
            $existente = DB::table('lideres')->where('cedula', $cedula)->where('id', '!=', $id)->first();
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
                if ($nuevoNombre !== null) {
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
