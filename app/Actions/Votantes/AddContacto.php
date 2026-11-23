<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Models\LiderContacto;
use App\Support\MigracionFlags;

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
        $contacto = $lider->contactos()->create([
            'tipo' => $data['tipo'],
            'valor' => $data['valor'],
            'principal' => $data['principal'] ?? false,
            'estado' => $data['estado'] ?? 'activo',
        ]);
        // A phone completes the ficha's pending "Teléfono" (an acta's ficha, an Electo import).
        if (in_array($contacto->tipo, MigracionFlags::PHONE_CONTACTS, true)) {
            MigracionFlags::clearCompleted($lider->id);
        }

        return $contacto;
    }
}
