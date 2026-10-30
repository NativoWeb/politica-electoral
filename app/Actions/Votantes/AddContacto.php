<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Models\LiderContacto;

class AddContacto
{
    use ScopesTerritory;

    // Territory and campaign are checked before the payload is validated, so callers validate after calling authorizeFor().
    public function authorizeFor(string $liderId): Lider
    {
        $lider = Lider::find($liderId);
        if (! $lider) {
            throw new BusinessRuleException('Lider no encontrado.', 404);
        }
        $this->authorizeLiderRow($lider, true);

        return $lider;
    }

    public function handle(Lider $lider, array $data): LiderContacto
    {
        return $lider->contactos()->create([
            'tipo' => $data['tipo'],
            'valor' => $data['valor'],
            'principal' => $data['principal'] ?? false,
            'estado' => $data['estado'] ?? 'activo',
        ]);
    }
}
