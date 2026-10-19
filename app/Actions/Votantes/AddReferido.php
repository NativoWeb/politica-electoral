<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use Illuminate\Support\Facades\DB;

// Marks $referidoId as referred by $referenteId (through the referente's cedula).
class AddReferido
{
    use ScopesTerritory;

    public function handle(string $referenteId, string $referidoId): void
    {
        $referente = DB::table('lideres')->where('id', $referenteId)->first();
        if ($referente) {
            $this->authorizeGeo($referente->geographic_unit_id);
        }
        if (! $referente || ! $referente->cedula) {
            throw new BusinessRuleException('El referente debe tener cédula registrada.');
        }

        $referido = DB::table('lideres')->where('id', $referidoId)->first();
        if (! $referido) {
            throw new BusinessRuleException('Persona no encontrada.', 404);
        }
        $this->authorizeGeo($referido->geographic_unit_id);

        $nameParts = explode(' ', $referente->nombre, 2);

        DB::table('lideres')->where('id', $referidoId)->update([
            'referente_documento' => $referente->cedula,
            'referente_nombre' => $nameParts[0] ?? '',
            'referente_apellido' => $nameParts[1] ?? '',
            'updated_at' => now(),
        ]);
    }
}
