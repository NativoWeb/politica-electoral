<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use Illuminate\Support\Facades\DB;

// Marks $referidoId as referred by $referenteId (through the referente's cedula).
// Both records must belong to the user's own campaign: a cédula only identifies a referente inside one campaign.
class AddReferido
{
    use ScopesTerritory;

    public function handle(string $referenteId, string $referidoId): void
    {
        $referente = DB::table('lideres')->where('id', $referenteId)->first();
        if (! $referente) {
            throw new BusinessRuleException('Persona no encontrada.', 404);
        }
        $this->authorizeLiderRow($referente, true);
        if (! $referente->cedula) {
            throw new BusinessRuleException('El referente debe tener cédula registrada.');
        }

        $referido = DB::table('lideres')->where('id', $referidoId)->first();
        if (! $referido) {
            throw new BusinessRuleException('Persona no encontrada.', 404);
        }
        $this->authorizeLiderRow($referido, true);
        if ($referido->campaign_id !== $referente->campaign_id) {
            throw new BusinessRuleException('El referente y el referido deben ser de la misma campaña.');
        }

        $nameParts = explode(' ', $referente->nombre, 2);

        DB::table('lideres')->where('id', $referidoId)->update([
            'referente_documento' => $referente->cedula,
            'referente_nombre' => $nameParts[0] ?? '',
            'referente_apellido' => $nameParts[1] ?? '',
            'updated_at' => now(),
        ]);
    }
}
