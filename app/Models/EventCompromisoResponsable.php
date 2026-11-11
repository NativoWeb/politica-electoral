<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

// Who answers for a compromiso: a ficha (lider_id, only when someone confirmed it) or just a name and phone.
class EventCompromisoResponsable extends Model
{
    use HasUuids;

    protected $table = 'event_compromiso_responsables';

    protected $fillable = ['orden', 'nombre', 'telefono', 'lider_id', 'sugerencia_rechazada'];
}
