<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class UpdateVotante
{
    use ScopesTerritory;

    // $data must already be restricted to the fields the caller may change.
    public function handle(string $id, array $data): Lider
    {
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

        try {
            DB::transaction(fn () => $lider->update($data));
        } catch (UniqueConstraintViolationException) {
            throw new BusinessRuleException("Ya existe un líder con cédula {$cedula}.");
        }

        return $lider;
    }
}
