<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A ficha of Votantes (lideres) that belongs to an organization with a role.
class OrganizacionMiembro extends Model
{
    use HasUuids;

    public const ROLES = [
        'representante_legal' => 'Representante legal',
        'gerente' => 'Gerente',
        'socio' => 'Socio',
        'afiliado' => 'Afiliado',
        'empleado' => 'Empleado',
        'otro' => 'Otro',
    ];

    protected $table = 'organizacion_miembros';

    protected $guarded = ['*'];

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }
}
