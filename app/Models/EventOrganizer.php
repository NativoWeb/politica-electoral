<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventOrganizer extends Model
{
    use HasUuids;

    protected $fillable = ['event_id', 'lider_id', 'nombre', 'cedula', 'telefono', 'rol'];
}
