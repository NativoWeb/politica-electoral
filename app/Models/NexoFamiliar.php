<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NexoFamiliar extends Model
{
    use HasUuids;

    protected $table = 'nexos_familiares';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'person_id', 'nombre', 'parentesco', 'cargo',
        'edad', 'gustos', 'observaciones', 'cedula', 'telefono',
    ];

    protected $casts = [
        'edad' => 'integer',
    ];
}
