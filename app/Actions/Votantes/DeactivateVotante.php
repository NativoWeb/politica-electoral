<?php

namespace App\Actions\Votantes;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\Lider;

class DeactivateVotante
{
    use ScopesTerritory;

    public function handle(string $id): Lider
    {
        $lider = Lider::find($id);
        if (! $lider) {
            throw new BusinessRuleException('No encontrado.', 404);
        }
        $this->authorizeLiderRow($lider, true);

        $lider->update(['is_active' => false]);

        return $lider;
    }
}
