<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;
use App\Models\LiderSeguimiento;
use App\Models\User;

class AddSeguimiento
{
    use ScopesTerritory;

    public function authorizeFor(string $liderId): Lider
    {
        $lider = Lider::find($liderId);
        if (! $lider) {
            throw new BusinessRuleException('Lider no encontrado.', 404);
        }
        $this->authorizeGeo($lider->geographic_unit_id);

        return $lider;
    }

    public function handle(Lider $lider, User $user, array $data): LiderSeguimiento
    {
        return LiderSeguimiento::create([
            'lider_id' => $lider->id,
            'user_id' => $user->id,
            'tipo' => $data['tipo'],
            'nota' => $data['nota'],
            'fecha' => $data['fecha'] ?? now(),
        ]);
    }
}
