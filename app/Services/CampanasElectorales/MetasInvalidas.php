<?php

namespace App\Services\CampanasElectorales;

use RuntimeException;

// A batch of goals that was not saved: each error says which item (index in the batch, or null for the whole batch),
// which field and why. The PUT turns it into field errors, the Excel import into "Fila N: …".
class MetasInvalidas extends RuntimeException
{
    /** @param array<int, array{indice: ?int, campo: ?string, mensaje: string}> $errores */
    public function __construct(public readonly array $errores)
    {
        parent::__construct($errores[0]['mensaje'] ?? 'Metas inválidas.');
    }
}
