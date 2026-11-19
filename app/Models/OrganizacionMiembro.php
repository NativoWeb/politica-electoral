<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A ficha of Votantes (lideres) that belongs to an organization with a role (list rol_miembro of the Centro de
// configuración).
class OrganizacionMiembro extends Model
{
    use HasUuids;

    protected $table = 'organizacion_miembros';

    protected $guarded = ['*'];

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }
}
