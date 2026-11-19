<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// One option of a list of the Centro de configuración (App\Support\Configuracion\CatalogoRegistry).
class CatalogoOpcion extends Model
{
    use HasUuids;

    protected $table = 'catalogo_opciones';

    protected $fillable = ['catalogo', 'valor', 'etiqueta', 'orden', 'activo', 'color', 'metadata'];

    protected $casts = [
        'orden' => 'integer',
        'activo' => 'boolean',
        'metadata' => 'array',
    ];

    // The editable state, as config_cambios keeps it before and after a change.
    public function estado(): array
    {
        return ['etiqueta' => $this->etiqueta, 'orden' => (int) $this->orden, 'activo' => (bool) $this->activo, 'color' => $this->color];
    }
}
